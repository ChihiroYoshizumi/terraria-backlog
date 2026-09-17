# Terraria Backlog Bridge (PHP / Laravel)

`docs/design.md` の PHP Bridge。Achievement 判定・Backlog Registry・Mapping 同期を担当する（Task 02 以降で実装）。
Task 01 時点では雛形と責務ディレクトリのみを用意する。

## 設計方針（詳細は `docs/design.md` §1, §2.1 参照）

- 永続 DB・永続 Queue・DB Session を前提にしない。`QUEUE_CONNECTION=sync` / `SESSION_DRIVER=array` / `CACHE_STORE=array`。
- Eloquent Model / Migration を MVP の前提にしない。
- Registry / Mapping の永続化先は Backlog（`app/Infrastructure/Backlog`、Task 05/06 以降で実装）。

## ディレクトリ構成

```text
bridge/app/Domain/               ドメインモデル置き場（Task 04 以降）
bridge/app/Application/          ユースケース置き場（Task 02 以降）
bridge/app/Infrastructure/Backlog/  Backlog クライアント・Registry/Mapping Repository 実装置き場（Task 03/05/06）
bridge/app/Http/                 Controller / FormRequest 等
bridge/tests/Unit/
bridge/tests/Feature/
```

## 必要な環境

`docs/design.md` §2.1 に従い **PHP 8.5 以上** を必須とする（`composer.json` の `require.php` も `^8.5`）。

Ubuntu / WSL では標準リポジトリの PHP が 8.5 に満たない場合があるため、`ppa:ondrej/php` を追加して導入する。

```bash
sudo add-apt-repository ppa:ondrej/php && sudo apt update
sudo apt install -y php8.5-cli php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip
php -v   # 8.5 以上であることを確認する
```

## セットアップ

```bash
cd bridge
composer install
cp .env.example .env
php artisan key:generate
```

DB は不要。マイグレーションは実行しない。

## 起動

```bash
cd bridge
php artisan serve
```

`GET /` と `GET /up`（ヘルスチェック）が DB 無しで応答することを確認できる。

## テスト（DB 無しで動作する）

```bash
cd bridge
composer test
# または
php artisan test
```

`.env.example` / `phpunit.xml` は `QUEUE_CONNECTION=sync` / `SESSION_DRIVER=array` / `CACHE_STORE=array` を明示しており、
`DB_*` 環境変数を一切設定せずに起動・テストが成功することを Task 01 の完了条件としている。

## API ルート

`routes/api.php` に `POST /api/v1/worlds/{worldKey}/snapshots`（`docs/design.md` §6.1）を Task 02 以降で追加する。
Task 01 時点ではプレースホルダーのみで、Snapshot endpoint の実処理は含まない。
