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

対象 runtime は **Terraria 1.3.0.8 / TShock 4.3.13**（正本は `docs/design.md` §2.3）。

#### 6.1 採用する hook / packet は実装時に確定する
- どの hook / packet を使うかを事前に決め打ちにしない。実装着手時に採用バージョンの実 API と実機挙動を確認して確定する。
- `GetDataHandlers.ChestItemChange` は**利用候補**とする。ただし TShock 4.3.13 の `GetDataHandlers.ChestItemEventArgs` が持つのは `ID` / `Slot` / `Stacks` / `Prefix` / `Type` / `Handled` だけで、**操作した player の情報を含まない**。この event 単体では `collection_change` Snapshot に必要な `trigger.playerNames` を満たせない。
- Quick Stack 専用の hook が存在することを前提にしない。
- そのため、drag / shift / quick stack の各操作について **dirty trigger と player attribution の両方**を取得できる方式を調査して採用する。調査対象は少なくとも以下。
  - `TShockAPI.GetDataHandlers.ChestItemChange`（chest ID / slot の dirty trigger。player は取れない）
  - `TShockAPI.GetDataHandlers.ChestOpen`（`ChestOpenEventArgs` は `Player` (TSPlayer) と chest 座標 `X` / `Y` を持つ）
  - `TShockAPI.TSPlayer.ActiveChest`（その player が現在開いている chest index）
  - `TerrariaApi.Server.ServerApi.Hooks.NetGetData`（`GetDataEventArgs` の `MsgID` (`PacketTypes`) と `Msg` から送信元 player を特定できる）
  - 関連する `PacketTypes`: `ChestGetContents` / `ChestItem` / `SyncPlayerChestIndex` / `ForceItemIntoNearestChest`
- 採用した hook / packet と、その根拠（実機で観測した経路）を Task 08 の PR に記載する。

#### 6.2 Achievement 判定と分離する
- raw packet / event delta から Achievement を判定しない。
- hook から読み取ってよいのは次の2点だけ。
  1. 「configured Collection Chest が dirty になった可能性がある」
  2. 「その操作を行った player」
- 約500ms debounce 後、server 側で configured Collection Chest の**全 slot を読み直す**。
- PHP へ送る Snapshot はこの読み直した最終状態だけから作る。event 引数の値を Snapshot の内容に流用しない。
- configured Collection Chest 以外は無視する。

#### 6.3 playerNames
- `collection_change` の `trigger.playerNames` は、同じ debounce window 内で観測した操作 player を重複排除して集約する。
- `ChestItemChange` 単体では player が取れないため、必要なら `NetGetData` / `ChestOpen` / `TSPlayer.ActiveChest` 等との相関で attribution する。
- player attribution が確実に取れない操作経路がある場合、推測で player を埋めない。代替仕様を Adapter 側で作らず、spec/design issue として報告して止める。

#### 6.4 Quick Stack
- 「Quick Stack hook」という具体的 API の存在を前提にしない。
- Terraria 1.3.0.8 / TShock 4.3.13 の実環境で、Quick Stack が実際にどの packet / hook 経路を通るかを確認する。Quick Stack は chest を開かずに実行されるため、`ChestOpen` / `ActiveChest` に依存した attribution が効かない可能性がある点に注意する。
- 通常 drag / shift / quick stack のすべてが、最終的に同じ final chest state 判定へ収束することを確認する。

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

対象 runtime は **Terraria 1.3.0.8 / TShock 4.3.13**。自動テスト・実機確認ともこの組み合わせで行う。

### 自動テスト
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

### 実機確認（Terraria 1.3.0.8 Vanilla client + TShock 4.3.13）
- drag 操作で `collection_change` を検知できる。
- shift 操作で `collection_change` を検知できる。
- quick stack で `collection_change` を検知できる。
- 上記の各操作で、可能な限り正しい `trigger.playerNames` を取得できる。
- final chest reread により、操作方法に依存せず同じ Snapshot に収束する。
- 取得できない操作経路があった場合、その経路と理由を記録する（推測値で埋めない）。

## 完了条件
- Terraria 1.3.0.8 Vanilla client のまま利用できる TShock 4.3.13 Plugin としてビルドできる。
- AC-01/04/08/09/18 の Adapter 側要件がテストで追跡できる。
- Task 07 の notification-only response を追加解釈なしで表示できる。
- 採用した chest change hook / packet と player attribution の方式が、実機で確認した根拠付きで PR に記載されている。
- drag / shift / quick stack のいずれでも `collection_change` を検知でき、final chest reread により同じ Snapshot に収束する。
- API / hook が不足して要件（特に `trigger.playerNames`）を満たせない場合、勝手な代替仕様を作らず spec/design issue として止めている。