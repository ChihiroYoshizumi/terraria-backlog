# TerrariaBacklog.Adapter (C# / TShock Plugin)

`docs/design.md` §2.2, §3 の Adapter。World / Chest 状態の観測結果を PHP Bridge へ送り、
PHP が確定した通知をゲーム内へ表示するだけの薄い TShock Plugin。

Task 01 時点では **no-op**。ロード時に最小限のログを出すだけで、gameplay hook の登録・
Snapshot 生成・PHP への HTTP 送信・Achievement 判定は行わない（後続 Task 08 で実装する）。

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
adapter/TerrariaBacklog.Adapter.csproj  Plugin 本体
adapter/TerrariaBacklogPlugin.cs        Plugin エントリポイント（Task 01: no-op）
adapter/Hooks/                          gameplay hook 置き場（Task 08）
adapter/Snapshots/                      World/Chest Snapshot 生成置き場（Task 08）
adapter/Transport/                      PHP への非同期 HTTP Sender 置き場（Task 08）
adapter/Tests/                          xUnit unit test project
```

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

検証対象の `TerrariaBacklog.Adapter.dll` は `net45` であり、そのまま `dotnet test` の
テストホストへロードすると非 Windows では Mono が必要になる。また `Terraria.Main` は
実際に稼働している TShock Server 内でのみ安全に構築できる（独立したテストプロセス内で
`new Main()` すると静的初期化で例外になる）。

そのためテストプロジェクト自体は `net9.0` のままとし、
`System.Reflection.MetadataLoadContext` でビルド済み `net45` アセンブリの
**メタデータだけ**を読んで契約を検証する（実行ランタイムに依存せず CI でも回せる）。
検証内容は次の5点。

1. `TerrariaPlugin` を継承している
2. `[ApiVersion]` が付いており、その値が `1.22` である（属性の有無ではなく値を固定する）
3. コンストラクタが1つで `Terraria.Main` を受け取る
4. `Initialize()` と `Dispose(bool)` を override している
5. Achievement / BacklogIssue / Registry / Mapping を含む型名が Adapter 側に存在しない

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
難易度は `-difficulty <0|1>`（`0`=Normal, `1`=Expert）で指定する。Terraria 1.3.0.8 に
Master mode は存在しない。既存 World を使う場合は `-world <path>` のみでよい。
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

### 手動 Smoke Test（実機確認が可能な環境向け）

Task 01 の完了条件のうち、以下は自動化できない手動確認である。
**このエージェントの実行環境には Terraria クライアント・GUI・Mono のいずれもないため、
この手順は実施していない（ドキュメント化のみ）。** 実機で確認する開発者向けの手順として記載する。

1. Mono を導入する（上記「Mono のインストール」）。`mono --version` が通ることを確認する。
2. `./scripts/setup-tshock.sh` と `./scripts/deploy-adapter.sh` を実行する。
3. `cd .tshock-server && mono TerrariaServer.exe -world worlds/dev.wld -autocreate 2`
   （リポジトリ直下からは `make tshock-run`）でサーバーを起動する。
4. サーバーのコンソールログに `[TerrariaBacklog.Adapter] loaded (version ...)` が
   出力されることを確認する（`TerrariaBacklogPlugin.Initialize()` のログ）。
5. World がロードされ、サーバーが接続待ち状態（`Listening on port ...`）になることを確認する。
6. Steam の Terraria > プロパティ > Betas から `1.3.0.8` を選択し、Vanilla クライアントを
   サーバーと同じバージョンに揃える。
7. その Vanilla クライアントからサーバー IP:ポート（既定 7777）へ接続する。
8. tModLoader や専用 MOD なしで接続できることを確認する（Vanilla クライアントのみが要件）。

### 配布物・生成物は Git 管理しない

`.tshock-server/`, `.tshock-cache/`, world save, server log, runtime config は
すべて `.gitignore` で除外している。
