# Task 01: プロジェクト土台と共有 Contract を作る

## 目的
後続 Task が同じ構造・同じ境界仕様を前提に実装できるよう、Laravel Bridge / C# Adapter / shared contract の最小構成を作る。

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

この Task では TShock hook / HTTP sender / Achievement 判定は実装しない。

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

## 想定変更ファイル
- `bridge/**`
- `adapter/**`
- `contracts/**`
- 必要なら root README / CI 設定

## 実装しないこと
- Backlog API 接続
- Snapshot endpoint の実処理
- Achievement 判定
- TShock hook
- DB / Redis / 永続 Queue / Outbox

## テスト・確認
- Bridge の test command が DB 無しで成功する。
- Adapter の unit test project が起動する。
- `snapshot-v1.schema.json` に example request が適合する。
- example response が notification-only contract になっている。
- Adapter 側に Achievement Key / Backlog Issue Key 用の型や定数を作っていない。

## 完了条件
- `bridge/`, `adapter/`, `contracts/` が後続 Task の実装先として使える。
- AC-01/02 を阻害する Client MOD 依存や専用 DB 依存が入っていない。
- 正本と矛盾する判断が必要になった場合は実装せず spec/design issue として報告する。