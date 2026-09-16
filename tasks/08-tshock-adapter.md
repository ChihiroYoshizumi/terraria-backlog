# Task 08: TShock Adapter を実装する

## 目的
TShock 上で Terraria の現在状態を観測し、薄い Adapter として Snapshot 送信と PHP 決定済み通知の表示だけを担う。

## 依存関係
- Task 01 完了
- Task 02 完了
- Task 07 と interface を合わせること

## 参照
- `docs/spec.md` §4, §6, §7, §9, AC-01, AC-04, AC-08, AC-09, AC-18
- `docs/specs/collection-chest.md`
- `docs/specs/world-identity.md`
- `docs/specs/reconciliation.md`
- `docs/design.md` §2.2〜§2.3, §5〜§7, §14〜§15

## 対象 AC
- AC-01
- AC-04
- AC-08
- AC-09
- AC-18

## 実装すること

### 1. TShock Plugin entrypoint を実装する
- plugin metadata を定義する。
- startup 時に runtime compatibility gate を通す。
- unsupported runtime では hook 登録 / Snapshot 送信を開始しない。

### 2. World Key を生成する
- 既定は `terraria:<Main.worldID>`。
- `WorldKeyOverride` 設定がある場合は override を使う。
- World 名は識別子に使わない。

### 3. Adapter 設定を実装する
少なくとも以下を持つ。
- `BridgeUrl`
- Adapter Bearer Token
- `WorldKeyOverride`
- `CollectionChestName`
- `ReconciliationIntervalSeconds`

`CollectionChestName` は固定文字列をコードへ埋め込まず、すべての chest filter で設定値を使う。

### 4. Snapshot builder を実装する
Full Snapshot に以下を含める。
- schemaVersion
- requestId
- reason
- observedAt
- runtime versions
- world identity
- raw world flags
- configured Collection Chest 全件の現在内容
- collection-change 時のみ `trigger.playerNames`

Adapter では Achievement Key を作らない。

### 5. startup Full Snapshot を送る
`GamePostInitialize` 相当で world 初期化後に:
1. runtime gate
2. current world/chest state capture
3. `reason=startup`
4. async sender へ enqueue

を行う。

### 6. Collection Chest change を実装する
- `GetDataHandlers.ChestItemChange` を dirty trigger として扱う。
- Quick Stack hook も dirty trigger として扱う。
- event delta 自体から Achievement を判断しない。
- 約500ms debounce 後、サーバーの現在 chest 全 slot を読み直す。
- configured Collection Chest 以外は無視する。
- 同 debounce window の playerNames を重複排除して集約する。

### 7. periodic reconciliation を実装する
- 既定60秒、設定可能。
- `reason=periodic` の Full Snapshot を送る。
- player recipient を持たせない。

### 8. `/backlog sync` を実装する
- command: `/backlog sync`
- permission: `terrariabacklog.sync`
- 実行時に `reason=manual` Full Snapshot を即送信する。
- C# 側では Mapping や Registry を解釈しない。

### 9. single-flight sender / coalescing を実装する
- HTTP request を同時に複数走らせない。
- `periodic` / `world_change` / `startup` の未送信 state Snapshot は最新状態へ coalesce 可。
- `collection_change` は player ACK routing 情報を持つため、periodic で上書き/破棄しない。
- 連続 collection change は latest chest state に更新しつつ playerNames を集合として保持する。

### 10. player ACK context の寿命を実装する
- pending collection trigger は送信成功または失敗応答を受けるまでメモリ保持する。
- 応答後は破棄する。
- process restart で失われてよい。
- DB / file queue / Outbox へ永続化しない。
- failed request の過去 player context を後続 periodic/manual のために保持し続けない。

### 11. notification renderer を実装する
PHP response の notification をそのまま表示する。

- `audience=players` -> `playerNames` 対象へ `message` 表示
- `audience=server` -> server console へ `message` 表示

Adapter 側で message や Registry result を再解釈しない。

### 12. HTTP failure をゲームループから分離する
- game thread で HTTP 完了を待たない。
- timeout / connection failure を log する。
- failure でも Terraria server を停止しない。
- Adapter 自身が成功 ACK を生成しない。

## 想定変更ファイル
- `adapter/Hooks/**`
- `adapter/Snapshots/**`
- `adapter/Transport/**`
- plugin entrypoint/config
- `adapter/Tests/**`

## 禁止事項
- Achievement Key を生成/解釈しない
- Backlog API を呼ばない
- Registry/Mapping/Issue Key を扱わない
- 永続 Queue / Outbox /独自DBを持たない

## テスト
- supported/unsupported runtime gate
- WorldKey default/override
- configured chest name only 対象
- drag/shift/quick stack が最終 chest state に収束
- debounce + player aggregation
- periodic が pending collection trigger を破棄しない
- single-flight sender
- process lifetime 内だけ player context を保持
- failure response 後に old player context を保持しない
- `audience=players` / `audience=server` を指定どおり表示
- Adapter コードに Achievement/Issue Key 依存がない

## 完了条件
- Vanilla client のまま利用できる TShock Plugin としてビルドできる。
- AC-01/04/08/09/18 の Adapter 側要件がテストで追跡できる。
- Task 07 の notification-only response を追加解釈なしで表示できる。