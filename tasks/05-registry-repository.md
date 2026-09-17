# Task 05: Achievement Registry Repository と冪等性を実装する

## 目的
Backlog を Achievement Registry として安全に使い、重複・timeout・並行実行でも誤登録や誤 ACK の原因を作らない。

## 依存関係
- Task 03 完了
- Task 04 完了

## 参照
- `docs/spec.md` §5.2, §8, AC-03, AC-05, AC-11, AC-12, AC-13
- `docs/specs/achievement-registry.md`
- `docs/design.md` §10, §20, §21

## 対象 AC
- AC-03
- AC-05
- AC-11
- AC-12
- AC-13

## 実装すること

### 1. RegistryIssue / RegistryIndex を実装する
Backlog Issue を直接 application 層へ漏らしすぎないよう、Registry 用 read model を作る。

必要情報:
- Backlog Project ID
- Issue ID / Issue Key
- Record Type
- Terraria World Key
- Terraria Key
- 完了状態

Snapshot 単位で:

```text
registryIndex[achievement_key] = RegistryIssue[]
```

を構築する。

### 2. Registry scan を実装する
- Backlog Issue List を対象 `TRAINING_YOSHIZUMI` Project ID に限定する。
- 全ページ取得する。
- PHP 側の final match でも以下を完全一致確認する。
  - Project ID
  - `Terraria Record Type === registry`
  - World Key
  - Terraria Key
- API 検索文字列だけを exact lookup とみなさない。
- scan failure を0件扱いしない。

### 3. `ensureRegistered` を実装する
`docs/design.md` §10.4 の分岐をそのままコード化する。

#### exact Registry 0件
1. lock 取得
2. lock 内でもう一度 latest exact Registry を確認
3. 無ければ Registry Issue 作成
4. Done Status へ更新
5. GET または限定再検索で保存確認
6. index 更新
7. `registered` を返す

#### exact Registry 1件
- 完了済み -> `already_registered`
- 未完了 -> Done 更新 -> 再取得確認 -> `registered`

#### exact Registry 複数
- 完了済み1件以上 -> logical `already_registered`
- 完了済み0件 -> fail closed
  - 新規作成しない
  - 既存Issueを自動完了しない
  - warning/log
  - `failed`

### 4. create/update の不明応答を扱う
timeout 等で結果不明の場合:
- 即座に create をやり直さない。
- 対象 World Key + Achievement Key を再検索する。
- 既存 Registry が確認できたらその状態から継続する。
- 確認不能なら success と断定しない。

### 5. cross-process file lock を実装する
`world_key + achievement_key` 単位で shared OS file lock を使う。

例:

```text
/var/lock/terraria-backlog/<sha256(world_key + achievement_key)>.lock
```

lock 範囲:

```text
latest exact lookup
 -> create/update
 -> verify
```

まで全体。

- 同一ホスト全 PHP worker で共有する。
- in-memory mutex だけで済ませない。
- 複数ホスト対応は追加しない。

### 6. Registry Issue 作成 payload を実装する
- Project は fixed/validated ProjectConfiguration から取得
- Record Type = `registry`
- World Key = 対象 world
- Terraria Key = Achievement Key
- 件名は人間が識別できる達成名
- status/issueType/priority は validated config を使用
- Adapter payload から Project/Field ID を受け取らない

## 想定変更ファイル
- `bridge/app/Infrastructure/Backlog/RegistryRepository*`
- `bridge/app/Domain/Registry*` または application read model
- lock helper
- `bridge/tests/Unit/**`
- `bridge/tests/Feature/**`

## 実装しないこと
- Mapping 課題完了
- ACK 文言生成
- 物理重複 Registry の削除・マージ
- distributed lock

## テスト
- none -> create -> done -> verify
- existing done -> no write
- existing incomplete -> repair
- create timeout -> re-search
- update timeout -> re-search/verify
- duplicate incomplete -> no write + failed
- duplicate with completed -> logical success、cleanupしない
- Project ID exact match
- World A の Registry が World B に混ざらない
- 並行リクエストが shared file lock で直列化される
- Snapshot 内複数Achievementでも full Registry scanをAchievementごとに繰り返さない

## 完了条件
- Registry 保存確認前に success 扱いしない。
- AC-11/12 の冪等性・不明状態 fail-closed がテストで担保される。
- Task 07 が RegistryResult を見て通知可否を判断できる。

---

## 実装状況

- status: **done**
- 実施日: 2026-09-17
- ブランチ: `tasks/05-registry-repository`

### 実施内容

#### Domain（Backlog 非依存の read model / 結果型）

- `bridge/app/Domain/Registry/RegistryIssue.php`: Registry 1 件の read model（Project ID / Issue ID / Issue Key / Record Type / World Key / Terraria Key / 完了状態 / Status ID）。`matches(projectId, worldKey, achievementKey)` が論理 Primary Key（`docs/design.md` §10.2）の完全一致判定。
- `bridge/app/Domain/Registry/RegistryIndex.php`: Snapshot-scoped `registryIndex[achievement_key] = RegistryIssue[]`。単一 World / 単一 Project に閉じ、`add()` で Project ID・World Key・Record Type の不一致を弾く（AC-13 の混入事故を型レベルで止める）。`forKey()` / `replace()` / `completedAchievementKeys()`。
- `bridge/app/Domain/Registry/RegistryResult.php` / `RegistryStatus.php` / `RegistryFailureReason.php`: `registered` / `already_registered` / `failed` と失敗理由コード。Task 07 は `isPersisted()` で通知可否を判断する。
- `bridge/app/Domain/Registry/RegistryRepository.php`: `loadIndex()` / `ensureRegistered()` の interface（`docs/design.md` §19.1）。
- `bridge/app/Domain/Registry/RegistrySubject.php`: `[Terraria Registry] Rod of Discord (item 1326)` 形式の件名生成。件名は表示用で一意性には使わない。
- `bridge/app/Domain/Registry/RegistryRecordType.php`: `registry` 値の定数化。

#### Infrastructure

- `bridge/app/Infrastructure/Backlog/RegistryIssueMapper.php`: Backlog Issue（生 JSON）→ `RegistryIssue`。**PHP 側の final match** の実装本体。Project ID 一致 / `Terraria Record Type === registry` / World Key 非空 / Terraria Key 非空 を満たさない Issue は `null`。完了判定は validated Done Status ID のみ（表示名や固定値を前提にしない）。
- `bridge/app/Infrastructure/Backlog/BacklogRegistryRepository.php`: 本 Task の中核。下記「§10.4 のコード化」参照。
- `bridge/app/Infrastructure/Backlog/BacklogClient.php`: `post()` / `patch()` を追加。Backlog API v2 の write は `application/x-www-form-urlencoded`（[add-issue](https://developer.nulab.com/docs/backlog/api/2/add-issue/) / [update-issue](https://developer.nulab.com/docs/backlog/api/2/update-issue/) の公式仕様を確認して実装）。例外方針は read と同一で、timeout は `BacklogTransportException`（= 結果不明）。
- `bridge/app/Infrastructure/Lock/`: `CrossProcessLockFactory` / `CrossProcessLock`（interface）、`FileLockFactory` / `FileLock`（`flock(LOCK_EX)` 実装）、`LockUnavailableException`。
- `bridge/config/backlog.php`: `registry_lock.directory` / `registry_lock.timeout` を追加（`.env.example` にも追記）。
- `bridge/app/Providers/BacklogServiceProvider.php`: `RegistryRepository` → `BacklogRegistryRepository`、`CrossProcessLockFactory` → `FileLockFactory` を binding。

### §10.4 の分岐をどうコード化したか

`ensureRegistered()` は Snapshot-scoped index の `forKey()` だけを見て分岐し、**書き込みが必要なときだけ** lock を取る。

| §10.4 の分岐 | 実装 |
| --- | --- |
| exact Registry が複数 / 完了済み1件以上 | `resolveDuplicates()` → warning ログ（`registry.duplicate_detected`）+ 論理 `already_registered`。lock も API 呼び出しもしない。削除・マージ・自動完了はしない |
| exact Registry が複数 / 完了済み0件 | `resolveDuplicates()` → warning ログ（`registry.duplicate_incomplete`）+ `failed(DuplicateIncomplete)`。**書き込み 0 回**、lock も取らない |
| exact Registry が1件 / 完了済み | `already_registered` を即返す。API を一切呼ばない（AC-11） |
| exact Registry が1件 / 未完了 | lock 取得 → `writeUnderLock()` |
| exact Registry が0件 | lock 取得 → `writeUnderLock()` |

`writeUnderLock()`（lock の内側、`docs/design.md` §21 の順序）:

1. `searchExact()` で **lock 内の最新 exact Registry を再確認**（World Key + Terraria Key で限定再検索 → PHP 側 final match）。ここで例外が出たら `failed(LookupFailed)` で打ち切る（scan failure を0件扱いしない）。
2. `index->replace()` で最新状態を反映。
3. 複数 → 上表の複数分岐へ / 1件完了済み → `already_registered` / 1件未完了 → `completeAndVerify()` / 0件 → `createAndVerify()`。
4. `createAndVerify()`: `POST /api/v2/issues` → `completeAndVerify()`。
5. `completeAndVerify()`: `PATCH /api/v2/issues/{issueKey}`（`statusId` = validated Done Status ID）→ **`GET /api/v2/issues/{issueKey}` で再取得確認**。exact 一致かつ完了であることを確認できて初めて `registered`。確認できなければ `failed(VerificationFailed)`。
6. 成功時のみ `index->replace()` で保存確認済みの状態へ更新。
7. `finally` で lock 解放（範囲は「最新確認 → create/update → verify」全体）。

create/update の応答不明（timeout 等）は `recoverFromUnknownWrite()`:

- 即座に作り直さない。`registry.write_result_unknown` を warning ログに出す。
- 対象 World Key + Achievement Key を限定再検索する。
- 再検索も失敗 → `failed(WriteResultUnknown)`。
- 複数見つかった → §10.4 の複数分岐へ。
- 1件見つかり完了済み → 保存されていたと確認できたので `registered`。
- 1件見つかり未完了 → **その状態から継続**して完了更新＋保存確認（再帰 recovery は1回に制限）。
- **0件だった場合もこの pass では再作成しない**（`failed(WriteResultUnknown)`）。Backlog の Custom Field 文字列検索を exact lookup とみなさない方針（§10.3）のもとでは「0件」を不在の確証にできず、ここで作成すると物理重複を生むため。次回 Snapshot の全件 scan（`loadIndex`）が正本となり、そこで作成または修復される。

### cross-process file lock

- `FileLockFactory::forAchievement(worldKey, achievementKey)` → `<lock dir>/<sha256(worldKey . "\0" . achievementKey)>.lock` を `fopen('c')` + `flock(LOCK_EX|LOCK_NB)` のポーリングで取得（既定上限 10 秒、20ms 間隔）。
- 無期限 blocking にしないのは、Backlog 遅延でゲーム側を待たせないため（`docs/spec.md` §9 / AC-18）。取得できなければ **書き込まず** `failed(LockUnavailable)`。
- lock file は解放時に削除しない（削除すると待機中プロセスが古い inode を掴んで排他が壊れる）。中身は正本ではない。
- 既定 directory は `storage/framework/terraria-registry-locks`、`TERRARIA_REGISTRY_LOCK_DIR` で `/var/lock/terraria-backlog` 等へ変更可能。同一ホストの全 PHP Worker が同じパスを共有する必要がある。
- distributed lock は導入していない（MVP 非対応）。

### 検証結果

- `cd bridge && ./vendor/bin/phpunit`: **372 tests / 1046 assertions すべて pass**（うち Task 05 追加分 44）。DB_* 未設定・DB 未使用・永続 Queue / Outbox なし。
- `cd bridge && composer test`: 同じく全 pass。ただし Task 04 の申し送りどおり `php artisan test` は pass 時でも exit code 1 を返す。原因は `tests/Unit/Domain/Achievement/WorldFlagEvaluatorTest::supportedFlagTable` の data provider が引数を1個多く渡している PHPUnit warning（`OK, but there were issues!`）で、**Task 05 以前から存在し本 Task の変更とは無関係**。別 Task で data provider を直せば解消する。
- `cd bridge && ./vendor/bin/pint --test`: passed。
- 実 Backlog API は一切呼んでいない（すべて `Http::fake()`）。
- テストが骨抜きでないことの確認（mutation 手動確認、いずれも実施後に元へ戻した）:
  - 重複 fail-closed の条件を無効化 → `it_fails_closed_without_writing_when_duplicates_are_all_incomplete` 失敗。
  - `matchRegistries()` の World Key 完全一致を削除 → `it_does_not_mix_world_a_registries_into_world_b` 失敗。
  - 保存確認から `! $verified->done` を落とす → `it_does_not_report_success_when_the_saved_state_cannot_be_verified` 失敗。
  - create 応答不明時に再検索せず再作成する → 3 tests 失敗。
  - `flock(LOCK_EX)` を `LOCK_SH` に変更 → 別プロセス直列化テストを含む 2 tests 失敗。
  - Achievement ごとに `loadIndex()` を呼び直す → 6 tests 失敗。
  - lock 取得を丸ごと外す → 5 tests 失敗。

### テスト（tasks の「テスト」11 項目との対応）

| 項目 | テスト |
| --- | --- |
| none -> create -> done -> verify | `BacklogRegistryRepositoryTest::it_creates_completes_and_verifies_when_no_registry_exists` / `it_builds_the_create_payload_from_validated_project_configuration` |
| existing done -> no write | `it_does_not_write_when_a_completed_registry_already_exists` |
| existing incomplete -> repair | `it_repairs_an_incomplete_registry_without_creating_a_new_one` / `it_re_checks_the_latest_registry_inside_the_lock_before_creating` |
| create timeout -> re-search | `it_researches_instead_of_recreating_when_the_create_response_is_lost` / `it_fails_closed_when_the_create_result_stays_unknown` |
| update timeout -> re-search/verify | `it_verifies_by_researching_when_the_update_response_is_lost` / `it_continues_from_the_researched_state_when_a_lost_update_had_not_been_applied` / `it_does_not_report_success_when_the_saved_state_cannot_be_verified` |
| duplicate incomplete -> no write + failed | `it_fails_closed_without_writing_when_duplicates_are_all_incomplete`（書き込み 0 回・追加リクエスト 0 件・lock 未取得を検証） |
| duplicate with completed -> logical success、cleanupしない | `it_treats_duplicates_with_a_completed_one_as_a_single_logical_achievement` |
| Project ID exact match | `it_requires_an_exact_project_id_match` / `it_ignores_issues_that_are_not_registry_records` / `RegistryIndexTest::registry_issue_matches_only_on_the_full_logical_primary_key` |
| World A の Registry が World B に混ざらない | `it_does_not_mix_world_a_registries_into_world_b` / `it_rejects_an_index_built_for_another_world` / `RegistryIndexTest::it_refuses_registries_from_another_world` |
| 並行リクエストが shared file lock で直列化される | `FileLockTest::concurrent_processes_are_serialized_by_the_shared_file_lock`（`proc_open()` で **実際に別 PHP プロセスを 3 つ起動**し、critical section の enter/leave 時刻が重ならないことを検証）/ `different_achievements_do_not_block_each_other` / `it_gives_up_instead_of_waiting_forever` |
| Snapshot 内複数Achievementでも full Registry scan を繰り返さない | `it_scans_the_registry_once_per_snapshot_not_once_per_achievement` / `it_never_repeats_a_full_scan_even_when_several_achievements_need_writes` |

その他に、scan failure を0件扱いしないこと（`it_does_not_treat_a_scan_failure_as_zero_results`）、lock 内 lookup 失敗時に書き込まないこと、lock 取得失敗時に書き込まないこと、AC-05（取り出し後の再同期で Registry が消えない）、ログ・`RegistryResult` に API Key が出ないこと、write 系 client の form encoding と例外方針、DI 結線を検証している。

テスト用の Backlog stub（`bridge/tests/Unit/Infrastructure/Backlog/FakeBacklog.php`）は **Issue List の絞り込み（`projectId` / `customField_*`）を意図的に無視して全件返す**。API の検索文字列を exact lookup とみなしていたら落ちるようにしてある。

### 後続 Task 06 / 07 が使う interface

```php
namespace App\Domain\Registry;

interface RegistryRepository
{
    public function loadIndex(WorldKey $world): RegistryIndex;      // Snapshot ごとに 1 回だけ

    public function ensureRegistered(
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult;
}
```

- `RegistryIndex::completedAchievementKeys(): list<string>` … Task 06 の `mappingIndex` と突合する「完了済み Registry set」。
- `RegistryIndex::forKey(AchievementKey|string): list<RegistryIssue>` … 個別参照。
- `RegistryResult::$status` … `RegistryStatus::Registered` / `AlreadyRegistered` / `Failed`。
- `RegistryResult::isPersisted(): bool` … **Task 07 はこれが true のときだけ**成功 ACK / 攻略課題の完了同期へ進む。
- `RegistryResult::wasWritten(): bool` … 今回書き込んだか（「登録しました」と「既に登録済みです」の出し分け用）。
- `RegistryResult::hasPhysicalDuplicates(): bool` / `$duplicates` … 物理重複の診断表示用（cleanup はしない）。
- `RegistryResult::$reason`（`RegistryFailureReason`）/ `$detail` / `toLogContext()` … 失敗理由の出し分けと構造化ログ。
- DI: `App\Domain\Registry\RegistryRepository` を container から解決すると `BacklogRegistryRepository` が返る。

### 未対応事項 / 申し送り

- Mapping 課題の完了、ACK 文言生成、Snapshot 処理全体のオーケストレーション（`docs/design.md` §12 の 1〜11）は本 Task のスコープ外。Task 06 / 07 が `loadIndex()` を Snapshot ごとに 1 回だけ呼ぶ前提で組み立てること。
- 物理重複 Registry の削除・マージ、distributed lock は実装していない（タスクの「実装しないこと」どおり）。
- create/update の応答不明かつ限定再検索が0件だった場合、その pass では再作成しない設計にした。`docs/design.md` §10.4 は「再作成する前に対象 key を限定して再検索する」としか書いておらず「0件なら作成してよい」とは書いていないため、fail closed 側に倒している。Task の制約「確認不能なら success と断定しない」「API 検索文字列だけを exact lookup とみなさない」とも整合する。次回 reconciliation の全件 scan で作成される。
- Registry 課題の説明欄に診断 metadata（World Key / Terraria Key / Achievement Type / 登録日時 / Achievement metadata の scalar 値）を書き込む。登録日時が実際の達成日時ではない旨も本文に明記している（`docs/spec.md` §5.2）。
- `php artisan test` の exit code 1（上記）は Task 04 由来の既存 warning。本 Task では `docs/**` と他 Task のテストに触れない方針のため未修正。