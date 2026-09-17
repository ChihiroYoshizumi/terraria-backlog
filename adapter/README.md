# TerrariaBacklog.Adapter (C# / TShock Plugin)

`docs/design.md` §2.2, §3 の Adapter。World / Chest 状態の観測結果を PHP Bridge へ送り、
PHP が確定した通知をゲーム内へ表示するだけの薄い TShock Plugin。

Task 08 で実装済み。Achievement 判定・Backlog API 呼び出し・Registry / Mapping /
Issue Key の解釈・永続 Queue は **一切持たない**（PHP Bridge の責務）。

## 対応バージョン（固定）

`docs/design.md` §2.3 に従い、以下の組み合わせのみをサポートする。正本は `docs/design.md` §2.3 で、
変更する場合は `scripts/setup-tshock.sh` の `TSHOCK_VERSION` / `TERRARIA_VERSION` と root README も併せて更新する。

| コンポーネント | バージョン |
| --- | --- |
| Terraria | 1.3.0.8 |
| TShock | 4.3.13 |
| .NET (adapter のビルド/実行対象) | `net45` / .NET Framework 4.5 (TShock 4.3.13 のビルド対象と一致させる) |
| Server API バージョン | `[ApiVersion(1, 22)]` (TShock 4.3.13 本体の宣言値) |
| サーバー実行環境 | Mono (macOS / Linux / WSL) または .NET Framework 4.5+ (Windows) |

## ディレクトリ構成

```text
adapter/TerrariaBacklog.Adapter.csproj  Plugin 本体 (net45)
adapter/TerrariaBacklogPlugin.cs        Plugin エントリポイント / 各部品の配線
adapter/Configuration/                  設定 (BridgeUrl / Token / ChestName / 間隔)
adapter/Runtime/                        runtime gate / World Key / periodic trigger
adapter/Hooks/                          chest change 観測 (debounce) と通知出力
adapter/Snapshots/                      Snapshot モデルと JSON 生成
adapter/Transport/                      coalescing queue / single-flight sender / HTTP
adapter/Json/                           依存ライブラリなしの最小 JSON writer/parser
adapter/Tests/                          xUnit test project (net9.0)
```

### `*.TShock.cs` という命名の意味

TShock / Terraria の型に触れるファイルだけ `*.TShock.cs` にしてある。
それ以外（純ロジック層）は TShock に依存しないため、**同じソースを net9.0 の
テストプロジェクトへも直接コンパイル**して普通の unit test を書いている
（`adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj` の `Compile Include`）。

| 層 | 例 | テスト方法 |
| --- | --- | --- |
| 純ロジック | debounce / coalescing / single-flight / World Key / runtime gate / JSON | net9.0 の xUnit で直接実行 |
| TShock 薄層 (`*.TShock.cs`) | packet hook / Chest 読み取り / 通知送信 / HTTP | メタデータ検証 + 実サーバー Smoke Test |
| entrypoint (`TerrariaBacklogPlugin.cs`) | hook 登録 / 配線 | メタデータ検証 + 実サーバー Smoke Test |

## 採用した hook と根拠（Terraria 1.3.0.8 / TShock 4.3.13）

Collection Chest の変更契機には **`ServerApi.Hooks.NetGetData` の packet 32 / 85** を使う。
TShock 4.3.13 の配布バイナリを逆コンパイルして確認した事実は次のとおり。

| 確認したこと | 結論 |
| --- | --- |
| `TShockAPI.GetDataHandlers.ChestItemEventArgs` は `ID / Slot / Stacks / Prefix / Type` のみ | **操作 player を取得できない**ため `trigger.playerNames` を満たせない。単体では不採用 |
| `GetDataHandlers.InitGetDataHandler()` の登録 packet は 31 / 32 / 33 等で **85 が無い** | Quick Stack では `ChestItemChange` も `ChestOpen` も発火しない |
| `Terraria.MessageBuffer.GetData` の `case 85` -> `Chest.ServerPlaceItem(whoAmI, slot)` -> `Chest.PutItemInNearbyChest` | Quick Stack は Chest を開かずサーバー側が近隣 Chest を直接書き換える。`ChestOpen` / `TSPlayer.ActiveChest` による attribution は効かない |
| `MessageBuffer.GetData` は vanilla 処理の前に `ServerApi.Hooks.InvokeNetGetData(ref msgId, this, ref index, ref length)` を呼ぶ。`GetDataEventArgs.Msg.whoAmI` が送信元 player index | packet 32 / 85 の**両方で player を特定できる** |
| `PacketTypes`（`TerrariaServer.exe` のグローバル名前空間）は `ChestItem = 32` / `ForceItemIntoNearestChest = 85`（= `Terraria.ID.MessageID.QuickStackChests`）。どちらも `Enum.IsDefined` を通る | hook が確実に呼ばれる |
| `HandlerCollection.Invoke` は `Handled` で打ち切らず全ハンドラを呼び、例外も catch する | 他 plugin の判断に左右されず観測でき、こちらの例外でサーバーも止まらない |

操作経路と検知の対応:

| 操作 | packet | Chest 特定 | player attribution |
| --- | --- | --- | --- |
| 通常ドラッグ | 32 `ChestItem` | payload 先頭 Int16 の chest ID | `Msg.whoAmI` |
| Shift 操作 | 32 `ChestItem` | 同上 | `Msg.whoAmI` |
| Quick Stack | 85 `ForceItemIntoNearestChest` | **不可**（近隣 Chest へサーバーが分配） | `Msg.whoAmI` |

Quick Stack は Chest を特定できないため「設定された Collection Chest を無条件に読み直す契機」
として扱う。いずれの経路も約 500ms debounce 後に **設定名と一致する Chest の全 slot を
game thread で読み直し**、その最終状態だけから Snapshot を作る（packet の差分値は使わない）。

## Terraria 1.3.0.8 のサーバーループに関する制約（重要）

`Terraria.Main.DedServ()` のループは

```csharp
if (Netplay.anyClients || ServerApi.ForceUpdate)
{
    ServerApi.Hooks.InvokeGameUpdate();
    Update();
    ServerApi.Hooks.InvokeGamePostUpdate();
}
```

となっており、**プレイヤーが1人も接続していない間は `GameUpdate` hook が呼ばれない。**

Adapter は Terraria state の読み取りと通知表示を game thread（= `GameUpdate`）だけで行うため、
無人の間は periodic reconciliation・chest change の読み直し・通知表示・`/backlog sync` の
capture がいずれも保留される。無人の間はワールド状態も進まないので観測の取りこぼしには
ならないが、Backlog 側で後から Mapping を足した場合の反映は次にループが回ったとき
（プレイヤー接続時）になる。無人でも常時照合したい場合は `-forceupdate` で起動する
（TShock が警告するとおり CPU を使い続ける）。

## 設定

`<TShock.SavePath>/TerrariaBacklog.Adapter.json`（既定 `tshock/TerrariaBacklog.Adapter.json`）。
存在しなければ既定値で自動生成される。

```json
{
  "BridgeUrl": "http://127.0.0.1:8080",
  "AdapterToken": "",
  "WorldKeyOverride": null,
  "CollectionChestName": "BACKLOG_COLLECTION",
  "ReconciliationIntervalSeconds": 60,
  "ChestChangeDebounceMilliseconds": 500,
  "ChestChangeMaxDelayMilliseconds": 3000,
  "RequestTimeoutSeconds": 10
}
```

- `AdapterToken` は環境変数 `TERRARIA_BACKLOG_ADAPTER_TOKEN` で上書きできる（設定ファイルに
  平文で置きたくない運用向け）。値はログにも診断文字列にも出力しない。
- `CollectionChestName` は設定値であり、固定文字列を監視条件に埋め込んでいない。
  起動スキャン・変更契機の判定・Quick Stack 後の再取得・periodic のすべてでこの値を使う。
- `WorldKeyOverride` が空なら World Key は `terraria:<Main.worldID>`。
- **設定不備・非対応 runtime では hook を登録せず Snapshot も送らない（fail closed）。**

## コマンド

```text
/backlog sync      # permission: terrariabacklog.sync
```

`reason=manual` の Full Snapshot を送る。C# 側では Mapping / Registry を解釈しない。

## ビルドに必要なもの: TShock 参照アセンブリ

`adapter/TerrariaBacklog.Adapter.csproj` は TShock が要求する `TerrariaPlugin` 基底クラス等を
使うため、TShock 配布物に含まれる `TerrariaServer.exe` と `ServerPlugins/TShockAPI.dll` を
コンパイル時参照として必要とする。これらはライセンス上・サイズ上の理由からリポジトリに
コミットしない。

TShock 4.3.13 には `OTAPI.dll` も `bin/` ディレクトリも存在せず、`TerrariaApi.Server`
（`TerrariaPlugin` / `ApiVersionAttribute`）と `Terraria.Main` はいずれも `TerrariaServer.exe`
に内包されている。そのため参照はこの2つだけでよい。

リポジトリ直下で以下を実行し、`.tshock-server/`（gitignore 済み）に展開する。

```bash
./scripts/setup-tshock.sh
```

既定では `adapter/*.csproj` は `../.tshock-server`（リポジトリ直下の `.tshock-server/`）を参照する。
別の場所に展開した場合は `-p:TShockServerDir=/path/to/tshock-server` でビルド時に上書きできる。

## ビルド

```bash
./scripts/setup-tshock.sh   # 初回のみ（TShock 参照アセンブリの取得）
dotnet build adapter/TerrariaBacklog.Adapter.csproj
```

`TargetFramework` は `net45` だが、`Microsoft.NETFramework.ReferenceAssemblies.net45` を
`PackageReference` しているため、Windows 以外（macOS / Linux / WSL）でも dotnet SDK だけで
ビルドできる。**ビルドに Mono は不要**（Mono が要るのはサーバーの起動時だけ）。
出力は `adapter/bin/<Configuration>/net45/TerrariaBacklog.Adapter.dll`。

## Unit Test

```bash
dotnet test adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj
```

テストプロジェクトは `net9.0` で、2種類の検証を持つ。

### 1. 純ロジックの unit test（同じソースを net9.0 へ直接コンパイル）

`*.TShock.cs` と `TerrariaBacklogPlugin.cs` 以外は TShock に依存しないため、
テストプロジェクトが `Compile Include="../**/*.cs"` で取り込んで普通に実行する。

- runtime compatibility gate（supported / unsupported）
- World Key の default / override、表示名を使わないこと
- 設定の既定値・検証・JSON round trip・Token の環境変数上書き・Token を診断文字列に出さないこと
- Snapshot URL の組み立て（Bridge route との一致）
- chest change の debounce・quiet period 延長・最大遅延・player 集約
- drag / shift / quick stack が 1 つの読み直し契機へ収束すること
- 設定名と一致しない Chest だけの変更を無視すること
- player を特定できない観測を推測で埋めず件数だけ残すこと
- coalescing（state 同士は最新へ / periodic が collection trigger を破棄しない /
  連続 collection は最新 state + playerNames の集合 / manual を吸収しない / 3 スロット上限）
- single-flight sender（in-flight 中に2本目を走らせない）
- ACK context の寿命（成功・失敗いずれの応答後も破棄、失敗後の periodic に過去 player を載せない）
- 復旧待ちフラグ（失敗で立つ / periodic・manual にだけ載る / server 通知の表示で解除 /
  プロセス再起動で失われる）
- `audience=players` / `audience=server` の振り分け、未知 audience の破棄、
  宛先なし通知を全体へ広げないこと
- Snapshot JSON が `contracts/snapshot-v1.schema.json` に適合すること
  （実際に `contracts/node_modules` の ajv へ通す。node / ajv が無い環境では
  構造アサーションのみにフォールバックする）

### 2. net45 アセンブリのメタデータ検証

`Terraria.Main` は稼働中の TShock Server 内でのみ安全に構築できるため、
`System.Reflection.MetadataLoadContext` でメタデータだけを読んで契約を固定する。

1. `TerrariaPlugin` を継承している
2. `[ApiVersion]` が付いており、その値が `1.22` である（属性の有無ではなく値を固定する）
3. コンストラクタが1つで `Terraria.Main` を受け取る
4. `Initialize()` と `Dispose(bool)` を override している
5. Achievement / BacklogIssue / Registry / Mapping / IssueKey を含む**型名**が存在しない
6. 同じ語を含む**メンバー名**（method / property / field）も存在しない
7. Backlog SDK 等を参照していない
8. `terrariabacklog.sync` permission が埋め込まれている

### net45 依存でテストできない部分

`*.TShock.cs` の中身（packet payload の読み取り、`Main.chest` / `NPC.downed*` の読み取り、
`TSPlayer` への送信、`HttpWebRequest`）は TShock ランタイムが要るため unit test にできない。
これらは下記の Smoke Test と実機確認で担保する。

## ローカル TShock Dedicated Server の起動と Plugin デプロイ

リポジトリ直下から実行する。

```bash
# 1. TShock 本体を取得（初回のみ、以後は差分がなければ再実行不要）
./scripts/setup-tshock.sh

# 2. Adapter を Release ビルドし、.tshock-server/ServerPlugins/ に配置する
./scripts/deploy-adapter.sh

# 3. TShock Dedicated Server を起動する（要 Mono。make tshock-run でも可）
cd .tshock-server
mono TerrariaServer.exe -world worlds/dev.wld -autocreate 2
```

`-autocreate <size>` を指定すると `worlds/dev.wld` が存在しない場合に自動生成される。
`<size>` は**ワールドサイズ**で `1`=Small / `2`=Medium / `3`=Large（難易度ではない）。
難易度に対応する CLI 引数は TShock 4.3.13 の `TerrariaServer.exe` に存在しない
（未知の `-` 引数はエラーにならず黙って無視される）。難易度を変える場合は
`-config <path>` で読み込む設定ファイルの `difficulty=0`（Normal）/ `difficulty=1`（Expert）を使う。
Terraria 1.3.0.8 に Master mode は存在しない。既存 World を使う場合は `-world <path>` のみでよい。
必要に応じて `-port <port>` でポートを指定できる（既定 7777）。

### Mono のインストール

TShock 4.3.13 は OS 非依存の単一 zip (`tshock_4.3.13.zip`) を配布しており、中身は
.NET Framework 4.5 向けの `TerrariaServer.exe` である。macOS / Linux / WSL では Mono が必要。

```bash
# macOS (Homebrew)
brew install mono

# WSL (Ubuntu) / Ubuntu
sudo apt update
sudo apt install -y mono-complete

# 確認
mono --version
```

Windows ネイティブでは .NET Framework 4.5 以上があれば `TerrariaServer.exe` を
直接実行できる（`mono` プレフィックス不要）。

### Smoke Test（クライアント不要。Mono だけで実施済み）

Terraria クライアントなしで確認できる範囲は Task 08 で実施済みである。
モックの PHP Bridge（HTTP 200 + notification-only response を返すだけのもの）を
`127.0.0.1:8080` に立て、`mono TerrariaServer.exe ... -forceupdate` で起動して確認した。

確認できたこと:

1. `[ApiVersion(1, 22)]` の Plugin が TShock 4.3.13 にロードされる
   （`Plugin TerrariaBacklog.Adapter v0.1.0.0 ... initiated.`）。
2. `GamePostInitialize` 後に `reason=startup` の Full Snapshot が
   `POST /api/v1/worlds/terraria:<worldID>/snapshots` へ
   `Authorization: Bearer <token>` 付きで届く。
3. その body が `contracts/snapshot-v1.schema.json` に適合する
   （`runtime` = `0.1.0` / `4.3.13` / `1.3.0.8`、`flags` 10 項目、
   `world.key` = `terraria:<Main.worldID>`）。
4. Bridge が落ちている間は送信が失敗してもサーバーは停止せず、エラーだけがログに残る。
5. Bridge 復旧後の最初の `periodic` に `recoveryPending: true` が付き、
   `audience=server` の通知を **server console にだけ** 表示した後にフラグが解除され、
   以後の周期では `recoveryPending` が付かない。

再現手順:

```bash
./scripts/setup-tshock.sh
./scripts/deploy-adapter.sh

# 任意の HTTP モックを 127.0.0.1:8080 に立てる。
# POST を受けたら {"requestId":..., "worldKey":..., "notifications":[...]} を返すだけでよい。

cd .tshock-server
export TERRARIA_BACKLOG_ADAPTER_TOKEN=dev-smoke-token
mono TerrariaServer.exe -world worlds/dev.wld -autocreate 1 -port 7777 -forceupdate
```

`-forceupdate` を付けるのは、無人の間 `GameUpdate` hook が回らない（上記「サーバーループに
関する制約」）ため。

### 実機確認（Terraria 1.3.0.8 Vanilla client が必要。**未実施**）

Collection Chest の操作経路は Terraria クライアントが要るため、
**Task 08 の時点では未実施**である（実装環境に Terraria クライアント・GUI が無い）。
以下を実機で確認すること。

準備:

1. Steam の Terraria > プロパティ > Betas から `1.3.0.8` を選び、Vanilla クライアントを
   サーバーとバージョンを揃える。
2. `./scripts/setup-tshock.sh` と `./scripts/deploy-adapter.sh` を実行する。
3. `<TShock.SavePath>/TerrariaBacklog.Adapter.json` に実際の `BridgeUrl` を設定し、
   `TERRARIA_BACKLOG_ADAPTER_TOKEN` に Bridge と同じ Adapter Token を設定する。
4. `cd .tshock-server && mono TerrariaServer.exe -world worlds/dev.wld -autocreate 2` で起動する。
5. クライアントから接続し、`/auth <token>` で SuperAdmin になる
   （`/backlog sync` には `terrariabacklog.sync` permission が要る）。
6. チェストを設置し、名前を設定値（既定 `BACKLOG_COLLECTION`）に変更する。

チェックリスト:

- [ ] **AC-01**: MOD なしの Vanilla 1.3.0.8 クライアントで接続でき、通常どおり遊べる。
- [ ] **drag**: アイテムをマウスでチェストのスロットへドラッグすると、
      約500ms 後に `reason=collection_change` の Snapshot が Bridge へ届く。
- [ ] **drag の attribution**: その Snapshot の `trigger.playerNames` に
      操作したプレイヤー名だけが入る。
- [ ] **shift**: Shift + クリックでチェストへ入れた場合も同様に検知でき、
      `trigger.playerNames` が正しい。
- [ ] **quick stack**: チェストを開かずに Quick Stack（チェスト UI の「まとめて収納」/
      インベントリの Quick Stack ボタン）を実行しても検知でき、
      `trigger.playerNames` が正しい。
- [ ] **収束 (AC-04)**: 上記3経路それぞれで最終的なチェスト内容が同じなら、
      届く `collectionChests[].items` も同じになる（操作方法に依存しない）。
- [ ] **複数プレイヤー**: 同じ debounce window 内で2人が操作した場合、
      `trigger.playerNames` に両方が重複なく入る。
- [ ] **複数スロット同時**: 複数スロットをまとめて更新しても Snapshot は1件に集約される。
- [ ] **対象外チェスト**: 別名のチェストだけを操作しても Snapshot が送られない。
- [ ] **ACK 表示**: Bridge が `audience=players` の通知を返すと、
      指定プレイヤーにだけゲーム内メッセージが表示される（全体チャットには出ない）。
- [ ] **server 通知**: `audience=server` の通知が server console にだけ出る。
- [ ] **`/backlog sync`**: 権限を持つプレイヤー / コンソールから実行すると
      `reason=manual` の Snapshot が届く。権限が無いプレイヤーは実行できない。
- [ ] **AC-18**: Bridge を停止した状態で納品・撃破しても、ゲーム進行が固まらない。
- [ ] **AC-09**: Bridge 停止中はゲーム内に成功 ACK が出ず、復旧後の再照合で
      残っているアイテムが登録され、server console に復旧通知が1件だけ出る。
- [ ] **AC-08**: Adapter を止めている間にボスを倒し、再起動後の `startup` Snapshot の
      `flags` に反映されている。
- [ ] 取得できない操作経路があれば、その経路と理由を記録する（推測値で埋めない）。

### 配布物・生成物は Git 管理しない

`.tshock-server/`, `.tshock-cache/`, world save, server log, runtime config は
すべて `.gitignore` で除外している。
