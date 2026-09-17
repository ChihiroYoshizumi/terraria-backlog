# Task 06: Mapping Repository と課題同期を実装する

## 目的
Backlog の攻略課題 Mapping を安全に読み取り、達成済み Registry に対応する未完了課題だけを完了させる。

## 依存関係
- Task 03 完了
- Task 05 完了

## 参照
- `docs/spec.md` §5.3, §8, AC-06, AC-07, AC-10, AC-14, AC-15, AC-19
- `docs/specs/backlog-mapping.md`
- `docs/specs/backlog-sync.md`
- `docs/design.md` §11, §12, §20, §21

## 対象 AC
- AC-06
- AC-07
- AC-10
- AC-14
- AC-15
- AC-19

## 実装すること

### 1. MappingIssue / MappingIndex を実装する
攻略課題用の read model を作る。

必要情報:
- Project ID
- Issue ID / Issue Key
- 完了状態
- Terraria Record Type
- Terraria World Key
- Terraria Key

Snapshot 単位で:

```text
mappingIndex[achievement_key] = MappingIssue[]
```

を構築する。

### 2. Mapping scan を実装する
Backlog から対象 Project / World の未完了 Issue を全ページ取得し、PHP 側で以下を判定する。

有効 Mapping:
- Project ID exact match
- `Terraria Record Type` が null / 空文字
- 未完了
- World Key が設定済み
- Terraria Key が設定済み

除外/診断:
- `Record Type === registry` -> Mapping から除外
- unknown non-empty Record Type -> ignore + diagnostic log
- World Key / Terraria Key の片方だけ -> invalid mapping として ignore + log
- Mapping 無しの一般/研修課題 -> silent ignore
- 完了済み一般/攻略課題 -> scan 対象から除外し、変更しない

### 3. Registry completed set と join する
Task 05 が返す完了済み Registry set と `mappingIndex` をメモリ上で照合する。

- Registry 未達成なら課題を変更しない。
- Registry 達成済みなら同一 Achievement に紐付く未完了課題をすべて完了候補にする。
- 1 Achievement -> 複数 Issue を許可する。

### 4. Mapping issue 完了処理を実装する
PATCH 前に必ず current issue を再取得し、以下を再確認する。
- Project ID exact match
- 現在も未完了
- Record Type が null / empty
- World Key exact match
- Terraria Key exact match

条件を満たす場合のみ configured Done Status へ更新する。

- 再取得時点で完了済みなら PATCH しない。
- Mapping が解除/変更されていたら更新しない。
- update failure は success 扱いしない。

### 5. reopen 対応を実装する
利用者が達成済み課題を reopen した場合、次回 reconciliation scan では未完了 Mapping として再度取得されるため、対応 Registry が存在すれば再完了できるようにする。

### 6. post-hoc Mapping を成立させる
Registry 登録後に Item が chest から消えていても、Registry が残っていれば後から Mapping された課題を完了できるようにする。

この Task では reconciliation trigger 自体は Task 07 に任せるが、`loadIncompleteIndex()` を呼び直せば新しい Mapping を検出できる Repository にする。

## 想定変更ファイル
- `bridge/app/Infrastructure/Backlog/MappingRepository*`
- `bridge/app/Application/SynchronizeMappedIssues*`
- mapping read model / index
- `bridge/tests/Unit/**`
- `bridge/tests/Feature/**`

## 実装しないこと
- periodic/manual trigger
- player ACK
- TShock Adapter
- Mapping の自動作成/補完

## テスト
- Registry 未達成 Mapping は更新しない
- Registry 達成後のみ完了
- same Achievement -> multiple issues を全て完了
- mapping-less issue ignored
- partial Mapping ignored + log
- unknown non-empty Record Type ignored + log
- completed general/training issue ignored
- Mapping 解除後は更新しない
- Project/World/Key mismatch を更新しない
- reopened issue を次回呼び出しで再完了
- post-hoc Mapping を再 scan で検出
- Snapshot 内複数 Achievement でも full Mapping scan を Achievement ごとに繰り返さない

## 完了条件
- 一般課題を推測で更新しない。
- AC-06/07/10/14/15/19 の Mapping 要件がテストで追跡できる。
- Task 07 から scan + sync を呼べば reconciliation が成立する。

## 実装状況

- status: **done**
- 実施日: 2026-09-17
- ブランチ: `tasks/06-mapping-sync`

### 実施内容

#### Domain（Backlog 非依存の read model / 結果型）

- `bridge/app/Domain/Mapping/MappingIssue.php`: 攻略課題 1 件の read model（Project ID / Issue ID / Issue Key / World Key / Terraria Key / 完了状態 / Status ID）。生成できた時点で「Project 一致・Record Type 空欄・両 Key 設定済み・状態 ID 読み取り済み」が確認済みであることを意味する。`matches()` / `isSameIssue()` が PATCH 直前の再確認に使う完全一致判定。
- `bridge/app/Domain/Mapping/MappingIndex.php`: Snapshot-scoped `mappingIndex[achievement_key] = MappingIssue[]`。`add()` が Project ID・World Key・**未完了であること**の不一致を弾く（完了済み課題と他ワールドの混入を型レベルで止める）。`forCompletedAchievements()` が Registry 完了済み set との join 本体。
- `bridge/app/Domain/Mapping/MappingCandidate.php` / `MappingRejection.php`: 「Mapping ではない」理由（`not_target_project` / `registry_record` / `invalid_record_type` / `partial_mapping` / `no_mapping` / `unreadable_issue`）と、課題ごとにログを出すかどうか（`shouldLog()`）。研修課題との同居が前提の理由は silent。
- `bridge/app/Domain/Mapping/CompletionResult.php` / `CompletionStatus.php` / `CompletionFailureReason.php`: `completed` / `already_completed` / `skipped` / `failed`。update failure を success へ丸めないための型。
- `bridge/app/Domain/Mapping/MappingRepository.php`: `loadIncompleteIndex()` / `complete()` の interface（`docs/design.md` §19.1）。

#### Infrastructure

- `bridge/app/Infrastructure/Backlog/MappingIssueMapper.php`: Backlog Issue（生 JSON）→ `MappingCandidate`。**PHP 側の final match** の本体。Custom Field 値は trim して比較し、空白のみは未設定扱い。完了判定は validated Done Status ID のみ。状態 ID を読めない応答は「未完了」と推測せず `unreadable_issue` として除外する。
- `bridge/app/Infrastructure/Backlog/BacklogMappingRepository.php`: 本 Task の中核（下記参照）。
- `bridge/app/Providers/BacklogServiceProvider.php`: `MappingRepository` → `BacklogMappingRepository`、`SynchronizeMappedIssues` を binding。

#### Application

- `bridge/app/Application/SynchronizeMappedIssues.php`: 完了済み Registry set と `mappingIndex` の join。1 回の同期につき **full Mapping scan は 1 回だけ**（index を渡されればそれを使い、無ければ自分で 1 回だけ読む）。`synchronizeWithRegistry(world, RegistryIndex)` で `RegistryIndex::completedAchievementKeys()` をそのまま渡せる。
- `bridge/app/Application/SynchronizeMappedIssuesResult.php`: 更新した課題 / 既に完了 / skip / 失敗の内訳。部分失敗を成功へ丸めない（AC-10）。

### 「一般課題を推測で更新しない」をどう担保したか

1. **scan 側（読み取り）**: `loadIncompleteIndex()` は Issue List を対象 Project ID だけで取得し、Backlog の Custom Field 文字列検索（[公式ドキュメント](https://developer.nulab.com/docs/backlog/api/2/get-issue-list/)上も部分一致）を絞り込みの根拠にしない。index に入るのは Project ID 完全一致 / Record Type 空欄 / World Key 完全一致 / Terraria Key 非空 / **未完了** をすべて満たすものだけ。Record Type が `registry` や未知の値、Mapping 属性が片方だけ、属性なし、状態を読めない、他ワールド、完了済みはすべて index に入らない。scan は読み取りのみで書き込み API を一切呼ばない。
2. **write 側（更新）**: `complete()` は PATCH の前に必ず `GET /api/v2/issues/{issueKey}` で current issue を再取得し、同一 Issue か / Project ID 一致 / **未完了** / Record Type 空欄 / World Key 一致 / Terraria Key 一致 をすべて再確認する。1 つでも崩れていれば `skipped` を返して **PATCH を 1 回も発行しない**。再取得自体に失敗した場合も `failed`（`lookup_failed`）で、推測更新はしない。
3. **テスト**: 上記の各ケースで `FakeMappingBacklog::writeCount()` と `patchedIssueKeys()` が 0 / 空であることを送信履歴から検証している。加えて `docs/design.md` §11 の「invalid mapping / invalid record type は診断ログを残す」も検証している。

### PATCH 前後のシーケンス（`docs/design.md` §21 Mapping 課題）

```text
GET /api/v2/issues/{issueKey}
  -> 同一 Issue + Project + 未完了 + Record Type 空欄 + World Key + Terraria Key を再確認
  -> 崩れていれば skipped（PATCH しない）
  -> 既に完了なら already_completed（PATCH しない）

PATCH /api/v2/issues/{issueKey}  statusId = configured done status
  -> 応答が完了を示す  -> completed
  -> 応答が不明瞭      -> GET で再確認 -> 未完了なら failed(verification_failed)
  -> 4xx（確定失敗）   -> failed(write_failed)
  -> timeout / 5xx / 429（結果不明） -> GET で再確認
        -> 実は適用済み -> completed（二重 PATCH しない）
        -> 未適用       -> failed(write_result_unknown)
```

Mapping は新規作成も属性補完もしないため cross-process lock は取っていない（Registry と異なり重複レコードを生む操作が無く、「完了へ揃える」冪等操作のみ）。

### 検証結果

- `cd bridge && PAO_DISABLE=1 ./vendor/bin/phpunit` … **OK (438 tests, 1265 assertions)** / exit code 0（Task 06 で +66 tests）
- `cd bridge && ./vendor/bin/pint --test` … `{"tool":"pint","result":"passed"}`
- 実装を一時的に壊してテストが落ちることを確認（いずれも確認後に revert 済み）:
  - PATCH 前の再確認 guard を無効化 → 7 failures（Mapping 解除 / 張替え / 手動完了 / 別 Project / 未知 Record Type 等）
  - 未知の Record Type を Mapping として受理 → 4 failures（scan / complete / mapper / 一般課題を更新しない）
  - Achievement ごとに `loadIncompleteIndex()` を呼ぶ → 3 failures（scan 回数）
  - PATCH 失敗を success 扱い → 3 failures（部分失敗を成功に丸めない）
  - 完了判定を常に false（完了済み課題を対象に含める）→ 17 failures

### テスト対応表

| タスクのテスト項目 | テスト |
| --- | --- |
| Registry 未達成 Mapping は更新しない | `SynchronizeMappedIssuesTest::it_does_not_touch_mappings_whose_achievement_is_not_registered` / `MappingIndexTest::it_only_returns_issues_whose_achievement_is_registered` |
| Registry 達成後のみ完了 | `SynchronizeMappedIssuesTest::it_completes_the_issue_once_the_achievement_is_registered` / `it_accepts_the_completed_registry_set_from_the_registry_index` |
| same Achievement -> multiple issues を全て完了 | `SynchronizeMappedIssuesTest::it_completes_every_issue_mapped_to_the_same_achievement` / `BacklogMappingScanTest::it_keeps_every_issue_mapped_to_the_same_achievement` |
| mapping-less issue ignored | `BacklogMappingScanTest::it_ignores_mapping_less_training_issues_without_touching_them`（PATCH 0 件を送信履歴で検証） |
| partial Mapping ignored + log | `BacklogMappingScanTest::it_ignores_partial_mappings_and_logs_a_diagnostic` |
| unknown non-empty Record Type ignored + log | `BacklogMappingScanTest::it_ignores_unknown_record_types_and_logs_a_diagnostic` |
| completed general/training issue ignored | `BacklogMappingScanTest::it_excludes_completed_general_and_mapped_issues` / `SynchronizeMappedIssuesTest::it_ignores_mapping_less_and_completed_training_issues`（書き込み 0 件） |
| Mapping 解除後は更新しない | `BacklogMappingCompletionTest::it_does_not_patch_when_the_mapping_was_removed` |
| Project/World/Key mismatch を更新しない | `BacklogMappingCompletionTest::it_does_not_patch_when_the_issue_belongs_to_another_project` / `it_does_not_patch_when_the_world_key_changed` / `it_does_not_patch_when_the_mapping_points_to_another_achievement` / `BacklogMappingScanTest::it_requires_an_exact_project_id_match` / `it_does_not_mix_world_a_mappings_into_world_b` |
| reopened issue を次回呼び出しで再完了 | `SynchronizeMappedIssuesTest::it_completes_a_reopened_issue_on_the_next_synchronization` / `BacklogMappingScanTest::it_detects_a_reopened_issue_on_a_later_scan` |
| post-hoc Mapping を再 scan で検出 | `SynchronizeMappedIssuesTest::it_completes_a_post_hoc_mapping_on_the_next_synchronization` / `BacklogMappingScanTest::it_detects_a_post_hoc_mapping_on_a_later_scan` |
| Snapshot 内複数 Achievement でも full scan を繰り返さない | `SynchronizeMappedIssuesTest::it_scans_the_mapping_index_once_per_synchronization_not_once_per_achievement`（`fullScanCount()` を数える）/ `it_reuses_a_preloaded_index_without_scanning_again` |

その他に、scan 失敗を 0 件扱いしないこと、update failure を success 扱いしないこと、応答が失われた PATCH の復旧、ページング、状態を読めない課題の除外、ログ・結果に API Key が出ないこと、DI 結線を検証している。

テスト用 Backlog stub は `bridge/tests/Unit/Infrastructure/Backlog/Mapping/FakeMappingBacklog.php`（Task 05 の `FakeBacklog` とは別ファイル）。**Issue List の絞り込みを意図的に無視して全件返す**ため、API の検索文字列を exact lookup とみなしていたら落ちる。scan 後・PATCH 前の利用者編集（reopen / Mapping 解除 / 手動完了 / 別 Project 化）も再現できる。

### Task 07 が使う interface

```php
namespace App\Domain\Mapping;

interface MappingRepository
{
    public function loadIncompleteIndex(WorldKey $world): MappingIndex;  // 同期ごとに 1 回だけ

    public function complete(MappingIssue $mapping): CompletionResult;
}
```

```php
namespace App\Application;

final readonly class SynchronizeMappedIssues
{
    public function synchronize(
        WorldKey $world,
        array $completedAchievementKeys,   // list<AchievementKey|string>
        ?MappingIndex $index = null,       // docs/design.md §12 手順 5 で読んだ index
    ): SynchronizeMappedIssuesResult;

    public function synchronizeWithRegistry(
        WorldKey $world,
        RegistryIndex $registry,           // completedAchievementKeys() を内部で使う
        ?MappingIndex $index = null,
    ): SynchronizeMappedIssuesResult;
}
```

- `MappingIndex::forCompletedAchievements(iterable $keys): list<MappingIssue>` … join 本体。同一 Issue を二重に返さない。
- `MappingIndex::count()` / `achievementKeys()` / `forKey()` … 診断・個別参照用。
- `SynchronizeMappedIssuesResult::completedIssueKeys(): list<string>` … 通知文言に使う「今回完了させた課題」。
- `SynchronizeMappedIssuesResult::hasFailures()` / `failures()` … 再試行・復旧待ち判定。`Failed` があるなら「全件反映済み」と通知しない。
- `SynchronizeMappedIssuesResult::withStatus(CompletionStatus)` / `toLogContext()` … 内訳と構造化ログ。
- `CompletionResult::wasUpdated()` / `isDone()` / `isFailure()` / `$reason` / `$detail`。
- DI: `App\Domain\Mapping\MappingRepository` / `App\Application\SynchronizeMappedIssues` を container から解決できる。
- 呼び出し順序（`docs/design.md` §12）: 手順 5 で `loadIncompleteIndex()` を 1 回、手順 8-9 で `synchronize(..., $index)`。`reason=manual` でも同じ経路を使う。`RegistryResult::isPersisted()` が false の Achievement を完了済み set に入れないのは Task 07 の責務。

### 未対応事項 / 申し送り

- periodic / manual の reconciliation trigger、player ACK、通知生成、Snapshot 処理全体のオーケストレーションは Task 07 のスコープ。
- Mapping の自動作成・属性補完・完了の自動取り消しは実装していない（タスクの「実装しないこと」どおり）。課題へのコメントも付けない（`docs/design.md` §12.2）。
- Mapping 更新では cross-process lock を取らない。Registry と違い「新規レコードを作る」操作が無く、再取得 → PATCH は完了へ揃える冪等操作のため。同時実行で二重 PATCH が発生しても結果は同じで、先に完了していれば後続は `already_completed` になる。
- Issue List の絞り込みに `statusId[]` や `customField_*` を使っていない。Backlog の Text 型 Custom Field 検索は部分一致であり、絞り込みに使うと取りこぼす可能性があるため、Project ID だけで取得して PHP 側で完全一致判定する（`docs/design.md` §20 の方針どおり）。課題数が増えて負荷が問題になる場合は、**PHP 側 final match を維持したまま** API 側の絞り込みを追加する余地がある。
- `Terraria Record Type` が空欄でも状態 ID を読めない Issue（`status` が欠けた応答）は Mapping として扱わず診断ログのみ残す。「未完了と推測して更新する」ことを避けるための fail closed 判断で、`docs/design.md` に明示はないが「完了済み課題を変更しない」（AC-19）を守るための解釈。