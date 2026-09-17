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
## 実装状況

- **status**: `implemented (実機確認 未実施)` — 実機確認が未完了のため `completed` にしない
- **実施日**: 2026-09-17
- **ブランチ / PR**: `tasks/08-tshock-adapter`

### 採用した hook / packet と根拠

Collection Chest の変更契機には **`TerrariaApi.Server.ServerApi.Hooks.NetGetData`（packet 32 / 85）** を採用した。
`.tshock-server/TerrariaServer.exe` と `.tshock-server/ServerPlugins/TShockAPI.dll`（TShock 4.3.13 / Terraria 1.3.0.8）を
`ilspycmd` で逆コンパイルして確認した事実は次のとおり。

| 調査対象 | 逆コンパイルで確認した事実 | 判断 |
| --- | --- | --- |
| `TShockAPI.GetDataHandlers.ChestItemChange` | `ChestItemEventArgs` は `ID / Slot / Stacks / Prefix / Type` のみ。`OnChestItemChange(short, byte, short, byte, short)` の引数にも player が無い | **単体では不採用**。`trigger.playerNames` を満たせない |
| `TShockAPI.GetDataHandlers.ChestOpen` | `ChestOpenEventArgs` は `Player` (TSPlayer) と `X` / `Y` を持つ。発火元は packet 31 (`PacketTypes.ChestGetContents`) | Quick Stack では発火しないため**不採用** |
| `TShockAPI.TSPlayer.ActiveChest` | `HandleChestOpen` (31) と `HandleChestActive` (33) で設定される | Quick Stack は Chest を開かないため**不採用** |
| `GetDataHandlers.InitGetDataHandler()` | 登録 packet は 4/5/6/8/12/13/16/17/19/20/21/22/26/27/28/29/30/31/32/33/34/38/41/42/44/45/47/48/50/51/55/60/61/63/64/65/75/76/79。**85 は無い** | TShock の GetDataHandlers では Quick Stack を観測できない |
| `Terraria.MessageBuffer.GetData` `case 85` | `Chest.ServerPlaceItem(whoAmI, slot)` を呼び、`Chest.PutItemInNearbyChest` が player から 200 units 以内の `Main.chest[i].item[j]` をサーバー側で直接書き換える | Quick Stack は packet 32 を伴わない。対象 Chest も packet からは決まらない |
| `TerrariaApi.Server.ServerApi.Hooks.NetGetData` | `MessageBuffer.GetData` が vanilla 処理の前に `InvokeNetGetData(ref msgId, this, ref index, ref length)` を呼ぶ。`GetDataEventArgs.Msg.whoAmI` が**送信元 player index** | **採用**。packet 32 / 85 の両方で player を特定できる |
| `PacketTypes`（`TerrariaServer.exe` のグローバル名前空間 enum） | `ChestItem = 32` / `ForceItemIntoNearestChest = 85`（= `Terraria.ID.MessageID.QuickStackChests`）。`GetData` の `Enum.IsDefined(typeof(PacketTypes), b)` を両方とも通る | hook が確実に呼ばれる |
| `TerrariaApi.Server.HandlerCollection<T>.Invoke` | `Handled` で打ち切らず全ハンドラを呼び、ハンドラ内の例外も catch してログするだけ | 他 plugin の判断に左右されず観測でき、こちらの例外でサーバーも止まらない |

操作経路と検知の対応:

| 操作 | packet | Chest 特定 | player attribution |
| --- | --- | --- | --- |
| 通常ドラッグ | 32 `ChestItem` | payload 先頭 Int16 の chest ID | `Msg.whoAmI` |
| Shift 操作 | 32 `ChestItem` | 同上 | `Msg.whoAmI` |
| Quick Stack | 85 `ForceItemIntoNearestChest` | 不可（サーバーが近隣 Chest へ分配） | `Msg.whoAmI` |

**player attribution が取れない経路は見つかっていない**（3経路とも packet 送信元から特定できる）。
特定できなかった観測は推測で埋めず、`CollectionChangeTrigger.UnattributedObservations` として件数のみ残し、
`trigger.playerNames` には入れない。`trigger` 自体も、1件も特定できなければ省略する。

### 実施内容

- Plugin entrypoint（`adapter/TerrariaBacklogPlugin.cs`）: 設定読み込み -> runtime gate -> hook 登録 -> `/backlog sync` 登録 -> sender 起動。
  設定不備 / 非対応 runtime では hook を登録せず Snapshot も送らない（fail closed）。`Initialize()` の例外は握り潰し、サーバー起動を止めない。
- 設定（`adapter/Configuration/`）: `BridgeUrl` / Adapter Token / `WorldKeyOverride` / `CollectionChestName` /
  `ReconciliationIntervalSeconds` ほか。Token は `TERRARIA_BACKLOG_ADAPTER_TOKEN` で上書き可能、ログ・診断文字列に出さない。
  `CollectionChestName` は固定文字列を埋め込まず、全 chest filter が設定値を使う。
- runtime gate / World Key / periodic trigger（`adapter/Runtime/`）: `SupportedVersionMatrix` は `1.3.0.8` / `4.3.13` のみ。
  World Key は `terraria:<Main.worldID>`、override 優先。World 名は使わない。
- chest change 観測（`adapter/Hooks/`）: NetGetData（32 / 85）で「dirty の可能性」と「操作 player」だけを拾い、
  約500ms debounce（連続入力時は最大 3s で強制発火）後に game thread で **設定名と一致する Chest の全 slot を読み直す**。
  packet の差分値は Snapshot に流用しない。設定 Chest が絡まない変更は無視する。
- Snapshot（`adapter/Snapshots/`）: `schemaVersion` / `requestId` (UUIDv4) / `reason` / `observedAt` (offset 付き ISO 8601) /
  `runtime` / `world` / `collectionChestName` / `flags`（design §8.1 の raw state 10 項目）/ `collectionChests` /
  `trigger.playerNames`（collection_change のみ）/ `recoveryPending`（periodic・manual のみ、true のときだけ）。
  **Achievement Key は作らない。**
- 送信（`adapter/Transport/`）: coalescing queue（collection / manual / state の3スロット上限）+ single-flight worker thread + `HttpWebRequest`。
  game thread で HTTP 完了を待たない。失敗してもサーバーを止めない。Adapter 自身は成功 ACK を作らない。
- ACK context: pending collection trigger の playerNames は要求の終了（成功・失敗いずれも）で破棄し、
  後続 periodic / manual へ引き継がない。永続化しない。
- 復旧待ちフラグ: 通信失敗・非成功応答で立ち、periodic / manual にのみ `recoveryPending: true` を付ける。
  `audience=server` の通知を console へ表示した時点で解除する。プロセス再起動で失われる。
- 通知表示（`NotificationDispatcher`）: `audience=players` は指定 Player のみ、`audience=server` は server console のみ。
  message を再解釈しない。未知 audience / 宛先なしの players 通知は破棄し、全体チャットへ広げない。
- JSON（`adapter/Json/`）: 外部ライブラリなしの最小 writer / parser。

### 設計上のトレードオフと発見

- **Plugin の `Order` を 1 にした。** `ServerApi.LoadPlugins()` は `orderby x.Plugin.Order, x.Plugin.Name` で
  `Initialize()` を呼ぶ。TShock 本体は `Order = 0` / `Name = "TShock"` で、culture-aware な文字列比較では
  `"TerrariaBacklog.Adapter"` が先に来るため、`Order = 0` のままだと `TShock.Log` が null の状態で初期化され、
  NullReferenceException でサーバー起動全体が中断した（Smoke Test で実際に発生）。
- **`Main.DedServ()` は `if (Netplay.anyClients || ServerApi.ForceUpdate)` のときだけ `GameUpdate` hook を回す。**
  プレイヤーが1人も接続していない間は periodic reconciliation・chest 読み直し・通知表示・`/backlog sync` の
  capture がいずれも保留される。無人の間はワールド状態も進まないため観測の取りこぼしにはならないが、
  Backlog 側で後から Mapping を足した場合の反映は次にループが回ったとき（プレイヤー接続時、または
  `-forceupdate` 起動時）になる。docs/design.md §7.4 の「60秒ごと」はこの前提で読む必要がある。
  Adapter 側で代替仕様は作らず、`/backlog sync` 実行時に保留である旨を実行者へ知らせるに留めた。
- `/backlog sync` は console 入力スレッドからも呼ばれるため、Terraria state の読み取りを game thread へ委譲した。

### 検証結果

| コマンド | 結果 |
| --- | --- |
| `./scripts/setup-tshock.sh` | OK（TShock 4.3.13 を `.tshock-server/` へ展開） |
| `dotnet build adapter/TerrariaBacklog.Adapter.csproj` | OK（0 warning / 0 error） |
| `dotnet test adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj` | OK（121 passed / 0 failed） |
| `./scripts/deploy-adapter.sh` | OK（`ServerPlugins/TerrariaBacklog.Adapter.dll`） |
| `cd contracts && npm install && npm run validate` | OK（5 checks すべて OK。契約ファイルは未変更） |
| Snapshot JSON の schema 適合 | OK。テスト内で `startup` / `periodic(recoveryPending)` / `collection_change` / `manual` の fixture を生成し、`contracts/node_modules` の ajv で `contracts/snapshot-v1.schema.json` に通した |

Mono による Smoke Test（Terraria クライアント不要の範囲）も実施し、以下を確認した。

- `[ApiVersion(1, 22)]` の Plugin が TShock 4.3.13 にロードされる。
- `GamePostInitialize` 後に `reason=startup` の Full Snapshot が
  `POST /api/v1/worlds/terraria:1341079479/snapshots` へ `Authorization: Bearer <token>` 付きで届く。
  body は schema 適合（`runtime` = `0.1.0` / `4.3.13` / `1.3.0.8`、`flags` 10 項目）。
- Bridge 停止中は送信が失敗してもサーバーは停止せず、エラーのみログに残る。
- Bridge 復旧後の最初の `periodic` に `recoveryPending: true` が付き、`audience=server` の通知を
  server console にだけ表示した後にフラグが解除され、以後の周期では付かない。

### 実機確認の未実施項目（Terraria 1.3.0.8 Vanilla client が必要）

実装環境に Terraria クライアント・GUI が無いため **未実施**。手順は `adapter/README.md`
「実機確認（Terraria 1.3.0.8 Vanilla client が必要。**未実施**）」に再現可能な形で記載した。

- [ ] AC-01: MOD なしの Vanilla 1.3.0.8 クライアントで接続でき、通常どおり遊べる
- [ ] drag 操作で `collection_change` を検知できる
- [ ] drag 操作の `trigger.playerNames` が正しい
- [ ] shift 操作で `collection_change` を検知できる
- [ ] shift 操作の `trigger.playerNames` が正しい
- [ ] quick stack で `collection_change` を検知できる
- [ ] quick stack の `trigger.playerNames` が正しい
- [ ] AC-04: 3経路とも final chest reread により同じ Snapshot に収束する
- [ ] 同一 debounce window の複数プレイヤーが `trigger.playerNames` に重複なく集約される
- [ ] 複数スロット同時更新が Snapshot 1件に集約される
- [ ] 設定名と異なるチェストの操作では Snapshot が送られない
- [ ] `audience=players` の通知が指定プレイヤーにだけ表示される（全体チャットに出ない）
- [ ] `audience=server` の通知が server console にだけ出る
- [ ] `/backlog sync` が `terrariabacklog.sync` 権限で動作し、権限なしでは実行できない
- [ ] AC-18: Bridge 停止中もゲーム進行がブロックされない
- [ ] AC-09: Bridge 停止中に成功 ACK が出ず、復旧後に残存アイテムが登録される
- [ ] AC-08: Adapter 停止中の撃破が再起動後の `startup` Snapshot の `flags` に反映される

### 未対応事項

- **実機確認が未実施**（上記チェックリスト）。このため status を `completed` にしていない。
- `reason=world_change` は Snapshot contract・coalescing・テストでは扱えるが、Boss 撃破等を早期検知する
  専用 hook は登録していない。docs/design.md §14.1 が「Boss event Hook は必須の正本ではない」
  「Hook が取れなくても Periodic Snapshot で補完できる状態だけを対象にする」としているため、
  MVP では periodic / startup での補完に委ねた。
- Task 07（Bridge の notification 生成）は未実装のため、`audience=players` の実 ACK 文言での
  結合確認はできていない。Adapter は `contracts/snapshot-response-v1.schema.json` に適合する
  response をそのまま表示するだけなので、Task 07 側の実装に追加解釈は不要。
- 無人サーバー中の periodic 停止（上記「設計上のトレードオフと発見」）は Adapter 側で代替仕様を
  作らず、運用手順（`-forceupdate`）と文書化に留めた。docs/design.md §7.4 の記述を更新するかは
  設計側の判断が要る。
