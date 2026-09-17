# Task 03: Backlog API Client・doctor・Project設定検証を実装する

## 目的
Backlog API への接続と運用設定の検証を PHP 側に集約し、誤設定のまま Registry / Mapping 更新へ進まないようにする。

## 依存関係
- Task 01 完了後
- Task 02 と並行可能

## 参照
- `docs/spec.md` §5.0, §9, §10, AC-16, AC-18, AC-19
- `docs/specs/operations-security.md`
- `docs/design.md` §9.1〜§9.4, §13, §16, §20

## 対象 AC
- AC-16
- AC-18
- AC-19

## 実装すること

### 1. Backlog API Client を作る
`bridge/app/Infrastructure/Backlog/BacklogClient` 相当を作り、後続 Repository から利用できる共通 client にする。

必須:
- Base URL と API Key を config から取得
- Nulab Backlog API v2 を呼べること
- connect timeout / request timeout を設定
- 429 / 5xx / timeout を例外または Result 型として区別
- API failure を「0件」と解釈しない
- response の `X-RateLimit-*` を診断ログへ残せるようにする
- API Key を例外ログ・HTTP debug logへ出さない

### 2. Issue list pagination helper を作る
- `count=100`
- `offset` を増やして全ページ取得
- 1ページだけで打ち切らない
- Project ID で絞り込める interface にする

後続 Task 05/06 が Registry / Mapping scan にそのまま使える形にする。

### 3. ProjectConfiguration を解決する
起動時または `terraria:doctor` 実行時に、少なくとも以下を解決・検証する。
- `BACKLOG_PROJECT_KEY === TRAINING_YOSHIZUMI`
- Project が存在し Project ID を取得できる
- 3 Custom Field が存在し Text 型で対象 Project 所属
  - Terraria Record Type
  - Terraria World Key
  - Terraria Key
- Done Status ID が対象 Project で有効
- Registry Issue Type ID が有効
- Registry Priority ID が有効
- World allowlist が設定済み
- Collection Chest 名が設定済み
- `TERRARIA_SUPPORTED_RUNTIME` が既知 matrix に含まれる
- version-pinned Item catalog が存在する

解決済み Project ID / field ID / status ID 等を後続 Repository が受け取れる read-only config object にまとめる。

### 4. `php artisan terraria:doctor` を実装する
- read-only command にする。
- Backlog 側の Project/Field を自動作成・修正しない。
- 各検証項目を OK/NG で表示する。
- 1つでも critical NG があれば non-zero exit code にする。
- API Key の値は絶対に表示しない。

### 5. fail-closed を徹底する
以下の場合、同期開始可能な状態として扱わない。
- Project Key が `TRAINING_YOSHIZUMI` 以外
- Custom Field/Status/Issue Type/Priority 不整合
- API 認証失敗
- runtime/catalog 設定不備

## 想定変更ファイル
- `bridge/app/Infrastructure/Backlog/**`
- `bridge/app/Console/Commands/**`
- `bridge/config/**`
- `bridge/tests/Unit/**`
- `bridge/tests/Feature/**`

## 実装しないこと
- Registry create/update
- Mapping 完了更新
- Achievement 判定

## テスト
- Project Key が固定値以外なら doctor failure
- Project / Custom Field / Status / Issue Type / Priority の不整合で failure
- Issue list pagination が全ページを取得
- 429 / 5xx / timeout を成功扱いしない
- API Key がログや command output に出ない
- Backlog API error を空結果として扱わない

## 完了条件
- 後続 Repository が `BacklogClient` と validated `ProjectConfiguration` を利用できる。
- `doctor` failure 状態で誤 Project へ書き込めない。
- AC-16/18/19 に必要な運用・安全境界がテストで追跡できる。