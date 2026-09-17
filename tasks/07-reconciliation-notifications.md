# Task 07: Reconciliation・通知/ACK・障害復旧を実装する

## 目的
Snapshot 処理全体を Application 層で統合し、startup / periodic / manual / collection_change のどの契機でも同じ正本から再照合できるようにする。

## 依存関係
- Task 02 完了
- Task 04 完了
- Task 05 完了
- Task 06 完了

## 参照
- `docs/spec.md` §8, §9, §10, AC-05, AC-06, AC-08, AC-09, AC-10, AC-11, AC-17, AC-18
- `docs/specs/reconciliation.md`
- `docs/specs/backlog-sync.md`
- `docs/design.md` §12, §14, §15, §17, §18

## 対象 AC
- AC-05
- AC-06
- AC-08
- AC-09
- AC-10
- AC-11
- AC-17
- AC-18

## 実装すること

### 1. `ProcessWorldSnapshot` を実装する
validated Snapshot DTO を受け取り、次の順序を1つの Application service で orchestrate する。

```text
1. Item 単位 validation / Achievement 評価
2. Registry scan -> registryIndex
3. Mapping scan -> mappingIndex
4. Achievement ごとに Registry ensure
5. 保存確認済み Registry set を確定
6. Registry set と mappingIndex を join
7. 対応 Mapping Issue を完了
8. notification を生成
9. notification-only response を返す
```

request-level validation は Task 02 に任せ、この service では validated DTO を前提にする。

### 2. reconciliation reason を同一パイプラインへ統合する
以下すべてが同じ `ProcessWorldSnapshot` を通るようにする。
- `startup`
- `periodic`
- `manual`
- `collection_change`
- `world_change`

Achievement 判定や Registry / Mapping 同期ロジックを reason ごとに重複実装しない。

### 3. periodic/manual で Registry/Mapping を再取得する
- 毎回現時点の Registry を scan する。
- 毎回現時点の Mapping を scan する。
- Registry 登録後に追加された Mapping を検出できるようにする。
- `/backlog sync` 相当の `reason=manual` でも periodic と同じ再照合を最後まで実行する。

### 4. collection_change の player ACK を実装する
`reason=collection_change` かつ、その request 内で対象 Item の Registry 保存を確認できた場合だけ player ACK を生成する。

例:

```text
[Backlog] Rod of Discord を登録しました。取り出してOKです。
```

ルール:
- Mapping 完了成功は ACK 条件にしない。
- Registry 保存未確認なら ACK しない。
- duplicate incomplete fail-closed なら ACK しない。
- 成功した Item のみ通知する。Partial failure で失敗 Item を混ぜない。
- 宛先は request の `trigger.playerNames` を PHP が選択し、完成済み notification として返す。

### 5. recovery notification を実装する
障害時に失敗した collection operation を、後続 periodic/manual reconciliation で再登録できた場合の扱いを明確に分ける。

#### immediate ACK との違い
- 過去 Player への item-specific ACK を復元しない。
- 過去の `trigger.playerNames` を永続保存しない。
- Item 名・元 Player を recovery notification に要求しない。

#### recovery notification
復旧後の periodic/manual reconciliation で同期成功を確認した場合、必要に応じて server console 向けの一般通知だけを生成する。

例:

```text
[Backlog] 復旧後の再同期が完了しました。
```

Response 例:

```json
{
  "audience": "server",
  "playerNames": [],
  "message": "[Backlog] 復旧後の再同期が完了しました。"
}
```

この通知は **player ACK ではなく運用上の復旧通知** として扱う。

### 6. 障害時の動作を実装する
- Backlog 429 / 5xx / timeout で success ACK を返さない。
- Registry 保存不明なら success を断定しない。
- Mapping update failure だけなら Registry は残し、次回 reconciliation で再試行可能にする。
- invalid Item があっても同 Snapshot の Boss/World Registry と Registry→Mapping 同期は継続する。

### 7. notification builder を実装する
`BuildNotifications` 相当を Application 層に置き、Adapter が解釈不要な完成済み命令を返す。

Notification に含めるのは:
- `audience`
- `playerNames`
- `message`

Achievement Key / Issue Key / RegistryResult / MappingResult は response に含めない。

### 8. 構造化ログを追加する
少なくとも以下を出せるようにする。
- `snapshot.received`
- `registry.created`
- `registry.exists`
- `registry.failed`
- `mapping.index_loaded`
- `issue.completed`
- `issue.completion_failed`
- `notification.generated`
- `reconciliation.completed`

秘密情報は含めない。

## 想定変更ファイル
- `bridge/app/Application/ProcessWorldSnapshot*`
- `bridge/app/Application/BuildNotifications*`
- response DTO
- logging 周辺
- `bridge/tests/Feature/**`

## 実装しないこと
- Adapter 側 player context 管理
- 永続 retry queue
- DB / Outbox
- 過去 Player ACK の復元
- Item イベント履歴の保存

## テスト
- periodic で post-hoc Mapping が反映される
- manual で post-hoc Mapping が即時反映される
- Registry 保存失敗時は player ACK なし
- collection_change 成功時だけ対象 Player に item-specific ACK
- Partial failure では成功 Item のみ通知
- 復旧後 reconciliation 成功時は server recovery notification のみ
- 復旧後に過去 Player への ACK を生成しない
- Mapping update failure 後、次回 sync で完了
- invalid Item があっても Boss/World/Mapping 再照合継続
- repeated reconciliation が不要な duplicate create/PATCH を生まない
- response に内部 Backlog 情報を含めない

## 完了条件
- AC-09/10/18 の障害復旧シナリオを Feature Test で再現できる。
- immediate player ACK と recovery server notification の意味がコード/テスト上で分離されている。
- DB/Outbox 無しという仕様範囲を超える保証を追加していない。
---

## 実装状況

- **status**: 完了
- **実施日**: 2026-09-17
- **ブランチ**: `tasks/07-reconciliation-notifications`

### 実施内容

#### 1. `ProcessWorldSnapshot`（`bridge/app/Application/ProcessWorldSnapshot.php`）

`SnapshotProcessor` を実装し、docs/design.md §12 の順序どおりに orchestrate する。

1. Achievement 評価（`EvaluateAchievements`。不正 Item は候補から外れるだけ）
2. Registry scan（`RegistryRepository::loadIndex()` を **Snapshot ごとに1回**）
3. Mapping scan（`MappingRepository::loadIncompleteIndex()` を **Snapshot ごとに1回**）
4. Achievement ごとに `ensureRegistered()`
5. `isPersisted()` が true のものだけを保存確認済み set に残す
6. `SynchronizeMappedIssues::synchronizeWithRegistry()` に両 index を渡して join
7. 対応 Mapping Issue を完了
8. `BuildNotifications` で通知生成
9. notification-only response

`startup` / `periodic` / `manual` / `collection_change` / `world_change` はすべてこの
1 本を通る。reason で分岐するのは手順 8 だけで、その分岐は `BuildNotifications` に閉じている。
periodic / manual でも毎回 Registry と Mapping を読み直すため、後付け Mapping は
`/backlog sync` 相当（`reason=manual`）でも即時に反映される。

#### 2. immediate ACK と recovery notification の分離

`BuildNotifications`（`bridge/app/Application/BuildNotifications.php`）に **別メソッド** として置き、
`ProcessWorldSnapshot` からも別々に呼ぶ。両者は条件・宛先・文言・入力が一切重ならない。

| | `immediatePlayerAcks()` | `recoveryNotifications()` |
| --- | --- | --- |
| 契機 | `reason=collection_change` のみ | `reason=periodic` / `manual` のみ |
| 追加条件 | 対象 Item の Registry 保存確認 | `recoveryPending` かつ再照合が全件成功 |
| 宛先 | 今回の `trigger.playerNames`（重複排除） | server console 1 件のみ |
| 文言 | `[Backlog] <Item 名> を登録しました。取り出してOKです。` | `[Backlog] 復旧後の再同期が完了しました。` |
| 受け取る入力 | `list<ConfirmedItemRegistration>` | `bool $reconciled` のみ |

分離を型でも担保している。`ConfirmedItemRegistration::fromRegistryResult()` は
**Item Achievement かつ `RegistryResult::isPersisted()` が true** のときしか生成できず、
保存未確認・duplicate incomplete の fail closed・Boss / World Achievement は ACK 経路へ
入り込めない。Mapping 完了の成否は ACK 条件に入らない（`CompletionResult` は
この経路へ渡していない）。

逆に `recoveryNotifications()` は **Item も Player も引数に取らない**。過去の
`trigger.playerNames` を保存する場所がコード上どこにも無く、item-specific ACK の
復元は構造的に不可能である。

#### 3. 障害時

- Registry 保存未確認 / duplicate incomplete では ACK を 1 件も出さない。
- Mapping 更新だけの失敗では Registry を巻き戻さず、ACK も出す（ACK は Registry
  保存確認だけを意味するため）。次回 reconciliation で課題完了を再試行する。
- 不正 Item があっても同 Snapshot の Boss / World Registry と Registry→Mapping 同期は継続し、
  「処理失敗」にも数えない。
- 再照合を完了できず、返せる通知も無い場合だけ **HTTP 503**（`SnapshotOutcome::RetriableFailure`）を返す
  （docs/design.md §15.3, §18.2）。Adapter はこれで復旧待ちフラグを立て、次の
  periodic / manual に `recoveryPending: true` を載せる。response **body** は
  notification-only のままで、`contracts/snapshot-response-v1.schema.json` は変えていない。
  `collection_change` の partial failure は成功分の ACK を届けるため 200 を返す。

#### 4. 構造化ログ

`snapshot.received` / `registry.created` / `registry.exists` / `registry.failed` /
`mapping.index_loaded` / `issue.completed` / `issue.completion_failed` /
`notification.generated` / `reconciliation.completed` を `request_id` / `world_key` /
`reason` 付きで出力する。Player Name と秘密情報は出さない（宛先は件数だけ記録）。

#### 5. 暫定デバッグ実装の削除

`LoggingSnapshotProcessor` / `DebugSnapshotLoggingServiceProvider` /
`bootstrap/providers.php` の登録 / `config/terraria.php` の `debug_log_achievements` を削除した
（`.env.example` には元から記載が無かった）。`tasks/08-tshock-adapter.md` の参照箇所には
「Task 07 で削除済み」と追記した。

#### 6. `isDefiniteWriteFailure` の共通化

Task 07 は Backlog へ直接書き込まない（すべて `RegistryRepository` /
`MappingRepository` 経由）ため 3 つ目のコピーは発生していない。あわせて既存の 2 つを
`App\Infrastructure\Backlog\Support\WriteFailureClassifier::isDefinite()` へ集約した
（振る舞いは不変）。

### 検証結果

| コマンド | 結果 |
| --- | --- |
| `cd bridge && PAO_DISABLE=1 ./vendor/bin/phpunit` | OK (480 tests, 1445 assertions) |
| `cd bridge && ./vendor/bin/pint --test` | passed |
| `cd contracts && npm run validate` | OK（5 件すべて） |

追加テスト: `tests/Unit/Application/ProcessWorldSnapshotTest.php`（22 件、fake repository）と
`tests/Feature/Snapshot/SnapshotReconciliationTest.php`（7 件、HTTP endpoint から
`Http::fake()` の Backlog まで通す end-to-end）。実 Backlog へは接続しない。

主要テストは実装を一時的に壊して落ちることを確認した（確認後に復元済み）。

- ACK の `isPersisted()` ガードを外す → 3 件失敗（保存失敗 / fail closed / partial failure）
- item ACK の `collection_change` 限定を外す → 6 件失敗（復旧通知の分離テストを含む）
- recovery notification の「全件成功」条件を外す → 部分失敗テストが失敗
- response に `achievementKeys` を足す → 7 件失敗
- 完了済み Mapping の除外を外す → repeated reconciliation テストが失敗

### 未対応事項

- 通常の `periodic` / `world_change` で Registry 書き込みが部分失敗した場合、
  `recoveryPending` が立っていなければ復旧通知の契機にはならない。これは
  docs/design.md §15.2 の表どおりの挙動（復旧待ちフラグは Adapter が持つ）。
- `docs/design.md §13.3` が許容する「短い transient retry を1回まで」は実装していない。
  Task 03 の `BacklogClient` に自動 retry が無く、Task 07 では追加しなかった。
