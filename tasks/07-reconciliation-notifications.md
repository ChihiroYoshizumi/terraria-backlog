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