# Task 03: Backlog API Client・doctor・Project設定検証

## 目的
Backlog 接続と設定検証を PHP 側に集約し、誤 Project・誤 Custom Field 等で同期を開始しない土台を作る。

## スコープ
- Backlog API Client
- 認証情報の安全な取り扱いとログ redact
- timeout / 429 / 5xx の共通処理
- `php artisan terraria:doctor`
- `TRAINING_YOSHIZUMI` 固定 assertion
- Project ID / 3 Custom Fields / Done Status / Issue Type / Priority / world allowlist / collection chest / runtime / item catalog の read-only 検証
- Issue list pagination 共通処理

## 非スコープ
- Registry create/update ロジック
- Mapping 完了ロジック

## 依存関係
- Task 01

## 仕様・設計参照
- `docs/spec.md` §5.0, §9, §10
- `docs/specs/operations-security.md`
- `docs/design.md` §9.1〜§9.4, §13, §16, §20

## 対象 AC
- AC-16
- AC-18
- AC-19

## テスト
- `BACKLOG_PROJECT_KEY !== TRAINING_YOSHIZUMI` は doctor failure
- Custom Field 型/所属不正は failure
- pagination が全ページ取得する
- 429/5xx/timeout を成功扱いにしない
- API Key がログへ出ない

## 完了条件
- `doctor` 失敗状態では同期開始できない設計/実装になっている
- 後続 Repository が Project ID exact match 可能な設定情報を取得できる
