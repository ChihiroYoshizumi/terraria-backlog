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
| Terraria | 1.4.5.6 |
| TShock | 6.1.0 |
| .NET (adapter のビルド/実行対象) | net9.0 (TShock 6.1.0 のビルド対象と一致させる) |

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
使うため、TShock 配布物に含まれる `TerrariaServer.dll` / `OTAPI.dll` / `TShockAPI.dll` を
コンパイル時参照として必要とする。これらはライセンス上・サイズ上の理由からリポジトリに
コミットしない。

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

## Unit Test

```bash
dotnet test adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj
```

`Terraria.Main` は実際に稼働している TShock Server 内でのみ安全に構築できる
（独立したテストプロセス内で `new Main()` すると静的初期化で例外になる）。
そのため unit test は `TerrariaBacklogPlugin` を実インスタンス化せず、
リフレクションで TShock Plugin としての契約（`[ApiVersion]` 属性 / `TerrariaPlugin` 継承 /
コンストラクタ形状）と、Achievement/Backlog 関連の型が Adapter 側に存在しないことを検証する。

## ローカル TShock Dedicated Server の起動と Plugin デプロイ

リポジトリ直下から実行する。

```bash
# 1. TShock 本体を取得（初回のみ、以後は差分がなければ再実行不要）
./scripts/setup-tshock.sh

# 2. Adapter を Release ビルドし、.tshock-server/ServerPlugins/ に配置する
./scripts/deploy-adapter.sh

# 3. TShock Dedicated Server を起動する
cd .tshock-server
./TShock.Server -world worlds/dev.wld -autocreate 2
```

`-autocreate <difficulty>` を指定すると `worlds/dev.wld` が存在しない場合に自動生成される
（`1`=Classic, `2`=Expert, `3`=Master）。既存 World を使う場合は `-world <path>` のみでよい。
必要に応じて `-port <port>` でポートを指定できる（既定 7777）。

Apple Silicon (macOS arm64) では TShock 6.1.0 に osx-arm64 バイナリが存在しないため、
`setup-tshock.sh` は `osx-x64` を取得する。Rosetta 2 が有効な環境であれば `TShock.Server` は
そのまま実行できる（`softwareupdate --install-rosetta` が未実施の場合は先に有効化すること）。

### 手動 Smoke Test（実機確認が可能な環境向け）

Task 01 の完了条件のうち、以下は自動化できない手動確認である。
**このエージェントの実行環境には Terraria クライアントも GUI もないため、この手順は
実施していない（ドキュメント化のみ）。** 実機で確認する開発者向けの手順として記載する。

1. 上記の手順で TShock Dedicated Server を起動する。
2. サーバーのコンソールログに `[TerrariaBacklog.Adapter] loaded (version ...)` が
   出力されることを確認する（`TerrariaBacklogPlugin.Initialize()` のログ）。
3. World がロードされ、サーバーが接続待ち状態（`Listening on port ...`）になることを確認する。
4. 対応バージョン (Terraria 1.4.5.6) の Vanilla クライアントからサーバー IP:ポートへ接続する。
5. tModLoader や専用 MOD なしで接続できることを確認する（Vanilla クライアントのみが要件）。

### 配布物・生成物は Git 管理しない

`.tshock-server/`, `.tshock-cache/`, world save, server log, runtime config は
すべて `.gitignore` で除外している。
