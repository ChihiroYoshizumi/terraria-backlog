# terraria-backlog

Terraria×Backlog 進捗管理システム。正本は `docs/spec.md`（What/Why）と `docs/design.md`（How）。
実装タスクの分割・進め方は `tasks/README.md` を参照。

このリポジトリは以下の3コンポーネントで構成される（`docs/design.md` §3, §4）。

```text
Vanilla Terraria Clients
          |
          v
TShock Dedicated Server + TerrariaBacklog.Adapter (C#)   ... adapter/
          | POST /api/v1/worlds/{worldKey}/snapshots
          v
Terraria Backlog Bridge (Laravel / PHP)                   ... bridge/
          | Backlog API v2
          v
Backlog (Achievement Registry / 攻略課題 + Mapping)
```

`contracts/` は Adapter と Bridge の間の Snapshot request/response の共有スキーマ。

## クイックスタート

```bash
# Bridge (DB 不要)
make bridge-install
make bridge-test

# Adapter (TShock 参照アセンブリの取得が必要)
make tshock-setup
make adapter-build
make adapter-test

# contracts (JSON Schema 検証)
make contracts-install
make contracts-validate
```

各コマンドの詳細は `bridge/README.md`, `adapter/README.md`, `contracts/` 配下を参照。

## ローカルで TShock Dedicated Server を起動する

対応バージョンは固定している。正本は `docs/design.md` §2.3 で、変更する場合に更新すべき
ファイルの一覧も同節にある（ここには再掲しない）。

| コンポーネント | バージョン |
| --- | --- |
| Terraria | 1.3.0.8 |
| TShock | 4.3.13 |
| Adapter のビルド対象 | .NET Framework 4.5 (`net45`) |
| サーバー実行環境 | Mono（macOS / Linux / WSL） |

Terraria クライアント側は Steam の Betas から `1.3.0.8` を選択してサーバーとバージョンを揃える。

### Mono をインストールする

TShock 4.3.13 は .NET Framework 4.5 向けの `TerrariaServer.exe` を配布しており、
macOS / Linux / WSL では Mono 上で実行する。

```bash
# macOS (Homebrew)
brew install mono

# WSL (Ubuntu) / Ubuntu
sudo apt update
sudo apt install -y mono-complete

# 確認
mono --version
```

Windows ネイティブで動かす場合は .NET Framework 4.5 以上が入っていれば
`TerrariaServer.exe` をそのまま実行できる（`mono` は不要）。

### 起動手順

```bash
# 1. TShock 本体を取得・展開する（.tshock-server/ に展開、git 管理しない）
./scripts/setup-tshock.sh

# 2. Adapter をビルドして ServerPlugins/ に配置する
./scripts/deploy-adapter.sh

# 3. TShock Dedicated Server を起動する（make tshock-run でも可）
cd .tshock-server
mono TerrariaServer.exe -world worlds/dev.wld -autocreate 2
```

起動後、サーバーのコンソールログに `[TerrariaBacklog.Adapter] loaded (version ...)` が出力されれば
Plugin のロードは成功している。対応バージョン (Terraria 1.3.0.8) の Vanilla Terraria クライアントから
接続できる（tModLoader・専用 MOD は不要）。手動確認手順の詳細は `adapter/README.md` の
「手動 Smoke Test」を参照。

**注記:** このリポジトリの Task 01 実装エージェントの実行環境には Terraria クライアント・GUI・Mono の
いずれもなく、TShock Dedicated Server の実際の起動・Plugin ロード確認・クライアント接続確認は
実施していない（自動化できるビルド・単体テスト・contract 検証のみ実施済み）。上記手順は
実機で確認できる開発者向けの再現手順として記載している。

TShock 本体・World データ・サーバーログ等は `.gitignore` で除外しており、リポジトリには
コミットしない。

## ディレクトリ構成

```text
bridge/      Laravel 13 / PHP 8.5 (Achievement 判定・Registry・Mapping・Backlog 同期)
adapter/     C# TShock Plugin (World/Chest Snapshot 観測・PHP 通知の表示)
contracts/   Adapter <-> Bridge の共有 JSON Schema と example
scripts/     ローカル開発用スクリプト (TShock 取得・Adapter デプロイ)
docs/        spec / design / 機能別詳細仕様
tasks/       実装タスク分割 (01〜09)
```
