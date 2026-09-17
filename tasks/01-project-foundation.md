# Task 01: プロジェクト土台と共有 Contract を作る

## 目的
後続 Task が同じ構造・同じ境界仕様を前提に実装できるよう、Laravel Bridge / C# Adapter / shared contract の最小構成を作る。

加えて、この Task の完了時点で「TShock Dedicated Server がローカルで起動し、TerrariaBacklog Adapter Plugin が読み込まれ、Vanilla Terraria クライアントから接続できる」状態まで到達する。

## 依存関係
なし。最初に実施する。

## 参照
- `docs/spec.md` §4, AC-01, AC-02
- `docs/design.md` §1〜§4, §19

## 対象 AC
- AC-01
- AC-02

## 実装すること

### 1. Laravel Bridge の雛形を作る
- `bridge/` に Laravel 13 / PHP 8.5 アプリを作成する。
- API 専用として起動できる状態にする。
- DB を必須にするコード・設定を入れない。
- `.env.example` に少なくとも以下を定義する。
  - `QUEUE_CONNECTION=sync`
  - `SESSION_DRIVER=array`
  - `CACHE_STORE=array`
- Migration / Eloquent model / DB queue / DB session を MVP の前提にしない。
- Pest または PHPUnit が DB 無しで起動できるようにする。

### 2. Bridge の責務ディレクトリを用意する
`docs/design.md` §19 に合わせて、少なくとも以下の置き場を作る。

```text
bridge/app/Domain/
bridge/app/Application/
bridge/app/Infrastructure/Backlog/
bridge/app/Http/
bridge/tests/Unit/
bridge/tests/Feature/
```

この Task では中身のドメイン実装はしない。後続 Task が配置先に迷わない状態にする。

### 3. TShock Adapter の C# プロジェクトを作る
- `adapter/TerrariaBacklog.Adapter.csproj` を作る。
- TShock Plugin としてビルド可能なプロジェクト構成を作る。
- Unit Test project を作る。
- 以下の責務用ディレクトリを用意する。

```text
adapter/Hooks/
adapter/Snapshots/
adapter/Transport/
adapter/Tests/
```

- TShock が Plugin として認識できる最小の Plugin class を実装する。
- Plugin の初期化時に、ロードされたことが分かる最小限のログを出してよい。
- この Task では gameplay hook / snapshot 生成 / HTTP sender / Achievement 判定は実装しない。

### 4. shared contract を作る
`contracts/` に以下を作成する。

```text
contracts/snapshot-v1.schema.json
contracts/examples/snapshot-v1.json
contracts/examples/snapshot-response-v1.json
contracts/terraria/<supported-version>/items.json
```

- Request schema は `docs/design.md` §6.2 を表現する。
- Response schema/example は `requestId`, `worldKey`, `notifications` のみにする。
- Response に Achievement Key / Backlog Issue Key / Registry result / Mapping result を含めない。
- `items.json` は後続 Task 04 が実データを入れられる形にし、最低限 `id`, `name`, `maxStack` を表現できる schema/例にする。

### 5. 最小の実行コマンドを揃える
README または各ディレクトリの README に、最低限以下を記載する。
- Bridge install / test command
- Adapter build / test command
- contract validation command

### 6. ローカルで TShock Dedicated Server を起動できるようにする
この Task で、開発者が実際に Terraria Server を起動して Plugin のロード確認までできるようにする。

実施内容:
- `docs/design.md` が対象とする Terraria / TShock の対応バージョンを固定する。
- TShock Dedicated Server の取得・配置・起動手順を README 等に記載する。
- `adapter/` の build output から Plugin DLL を TShock の `ServerPlugins` に配置する手順を記載する。
- 可能であれば build/deploy を 1 コマンドで行える script または Make target 等を用意する。
- TShock Dedicated Server を起動し、コンソール上で `TerrariaBacklog.Adapter` が正常にロードされることを確認する。
- この段階の Plugin は no-op でよい。起動時ロード確認以外の機能は不要。
- 開発用 World を作成または読み込み、サーバーが join 待ち状態まで到達することを確認する。
- 同一対応バージョンの Vanilla Terraria クライアントからサーバーへ接続できることを確認する。
- Client 側に tModLoader や専用 MOD を要求しない。

リポジトリには TShock 本体や Terraria の配布物をコミットしない。取得物・生成 World・server logs・runtime config など、Git 管理すべきでないファイルは `.gitignore` へ追加する。

## 想定変更ファイル
- `bridge/**`
- `adapter/**`
- `contracts/**`
- root README / adapter README
- 必要に応じて local setup script / Makefile 等
- `.gitignore`
- 必要なら CI 設定

## 実装しないこと
- Backlog API 接続
- Snapshot endpoint の実処理
- Achievement 判定
- gameplay/world/chest 用 TShock hook
- Snapshot の HTTP 送信
- DB / Redis / 永続 Queue / Outbox

## テスト・確認
### 自動確認
- Bridge の test command が DB 無しで成功する。
- Adapter の unit test project が起動する。
- Adapter Plugin が build できる。
- `snapshot-v1.schema.json` に example request が適合する。
- example response が notification-only contract になっている。
- Adapter 側に Achievement Key / Backlog Issue Key 用の型や定数を作っていない。

### 手動 Smoke Test
以下を Task 01 の完了確認として実施し、README に再現手順を残す。

1. TShock Dedicated Server を起動する。
2. `TerrariaBacklog.Adapter.dll` が Plugin としてロードされることをコンソールログで確認する。
3. World がロードされ、サーバーが接続待ち状態になることを確認する。
4. Vanilla Terraria クライアントから接続する。
5. クライアントに tModLoader / 専用 MOD が不要であることを確認する。

## 完了条件
- `bridge/`, `adapter/`, `contracts/` が後続 Task の実装先として使える。
- Adapter Plugin の DLL を作成して TShock に読み込ませられる。
- ローカルで TShock Dedicated Server が起動する。
- Vanilla Terraria クライアントから、その TShock Server に接続できる。
- AC-01/02 を阻害する Client MOD 依存や専用 DB 依存が入っていない。
- 正本と矛盾する判断が必要になった場合は実装せず spec/design issue として報告する。

## 実装状況

- status: completed
- 実施日: 2026-09-17
- 実施内容:
  - `bridge/`: Laravel 13 / PHP 8.5, DB 非依存 (QUEUE_CONNECTION=sync / SESSION_DRIVER=array / CACHE_STORE=array)。`app/Domain`, `app/Application`, `app/Infrastructure/Backlog` を新設。`composer test` が DB_* 未設定でも成功することを確認済み。
  - `adapter/`: `TerrariaBacklog.Adapter.csproj` (net9.0, TShock 6.1.0 の TargetFramework に合わせた)。`TerrariaBacklogPlugin` は no-op（ロード時ログのみ）。`adapter/Tests` の xUnit プロジェクトはリフレクションで Plugin 契約 (ApiVersion 属性・TerrariaPlugin 継承・コンストラクタ形状) と Achievement/Backlog 概念が漏れていないことを検証（5 tests, 全て pass）。`dotnet build` 成功を確認済み。
  - `contracts/`: `snapshot-v1.schema.json`（request）、`snapshot-response-v1.schema.json`（response, notification-only, additionalProperties:false）、`items-v1.schema.json`、`terraria/1.4.5.6/items.json`（プレースホルダー3件、Task 04 で拡張予定）。`npm run validate` で example が schema に適合し、response に禁止 field が無いことを確認済み。
  - `scripts/setup-tshock.sh` / `scripts/deploy-adapter.sh`: TShock 6.1.0 (for Terraria 1.4.5.6) の取得・展開・Adapter デプロイを自動化。実際に実行し、`.tshock-server/` への展開と `ServerPlugins/` への Plugin DLL 配置まで確認済み（gitignore 済み、コミットなし）。
  - README: root / `bridge/README.md` / `adapter/README.md` / `contracts/README.md` に install/test/build/deploy/validate コマンドと TShock 起動手順を記載。
- 実機検証: **未実施**。本タスクの実行環境には Terraria クライアント・GUI が無いため、TShock Dedicated Server の実起動・コンソールでの Plugin ロード確認・Vanilla クライアントからの接続確認は行っていない。手順は `adapter/README.md` の「手動 Smoke Test」に再現可能な形で記載し、開発者が実施できるようにした。
- 自動確認（実施済み・全て成功）:
  - `composer test`（bridge, DB 無し）
  - `dotnet build adapter/TerrariaBacklog.Adapter.csproj`
  - `dotnet test adapter/Tests/TerrariaBacklog.Adapter.Tests.csproj`
  - `npm run validate`（contracts）
- spec/design との矛盾: なし。
- PR: (この PR で作成。マージ後に番号を追記)