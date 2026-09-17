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
---

## 実装状況

- status: 完了 (PR レビュー待ち)
- 実施日: 2026-09-17
- 対象 AC: AC-16 / AC-18 / AC-19
- ブランチ: `tasks/03-backlog-client-doctor`

### 実施内容

| 区分 | 追加したもの |
| --- | --- |
| 設定 | `bridge/config/backlog.php`（base URL / API Key / Project Key / safety assertion / Custom Field 名・ID / Status ID / Issue Type ID / Priority ID / timeout / pagination）、`bridge/.env.example` に Task 03 セクションを追記 |
| API Client | `app/Infrastructure/Backlog/BacklogClient`、`BacklogResponse`、`RateLimitStatus`、`Support/SecretRedactor`、`Exceptions/*`（`BacklogApiException` / `Authentication` / `RateLimit` / `Server` / `Transport` / `Request` / `ProjectConfigurationException`） |
| Pagination | `app/Infrastructure/Backlog/IssueListPaginator`（`count=100` + `offset` で全ページ取得、Project ID 限定） |
| Project 設定検証 | `app/Infrastructure/Backlog/ProjectConfiguration`（read-only 値オブジェクト）、`ProjectConfigurationRepository`（`verify()` / `resolve()`）、`Diagnostics/ConfigurationCheck` / `ConfigurationReport` |
| doctor | `app/Console/Commands/TerrariaDoctorCommand`、`app/Console/Commands/Support/RuntimeConfigurationChecker` |
| DI | `app/Providers/BacklogServiceProvider` を追加し `bootstrap/providers.php` に登録 |

設計上の要点:

- 認証は `Backlog-API-Key` header のみ。`apiKey` query parameter を使わず、URL / access log に秘密情報を残さない（docs/design.md §13.1）。
- 429 / 5xx / timeout / 4xx / 非 JSON をそれぞれ別の例外にし、`isRetriable()` で区別する。いずれも「0件」「成功」として扱わない（§13.3 / §13.4 / §20）。
- `X-RateLimit-Limit / Remaining / Reset` を `RateLimitStatus` として読み取り、診断ログと 429 例外に載せる。
- Backlog は `projectId[]=...` 形式を要求するため、`http_build_query()`（`projectId[0]=...`）を使わず専用の query builder を持つ。
- `BACKLOG_PROJECT_KEY !== TRAINING_YOSHIZUMI` の場合は Backlog API を **1回も呼ばず** 失敗させる（誤 Project への接触自体を避ける）。
- API 障害で確認できなかった項目は OK ではなく `skipped` とし、doctor は non-zero で終了する。
- Custom Field は ID・型（typeId=1 / Text）・所属 Project・表示名の4点を照合する。ID 取り違えで別 Field を Mapping と誤認すると無関係な課題を完了にしてしまうため、表示名不一致も critical NG とした。
- `TERRARIA_SUPPORTED_RUNTIME` の既知 matrix は `RuntimeConfigurationChecker::SUPPORTED_RUNTIME_MATRIX`（`4.3.13:1.3.0.8`）に保持する。docs/design.md §2.3 が正本。
- Item catalog は `contracts/terraria/<version>/items.json` の存在・JSON 妥当性・`terrariaVersion` 一致・`items` 非空を確認する。

### 検証結果

| コマンド | 結果 |
| --- | --- |
| `cd bridge && composer test` | passed（75 tests / 162 assertions、DB_* 未設定） |
| `cd bridge && ./vendor/bin/pint --test` | passed |
| `php artisan terraria:doctor`（設定不備） | exit code 1。API Key（ダミー値）が出力に現れないことを grep で確認 |

追加テスト（Task 定義の6項目に対応）:

| Task の観点 | テスト |
| --- | --- |
| Project Key が固定値以外なら doctor failure | `TerrariaDoctorCommandTest::it_fails_when_the_project_key_is_not_the_fixed_value`（`Http::assertNothingSent()` 付き）、`ProjectConfigurationRepositoryTest` の同等ケース |
| Project / Custom Field / Status / Issue Type / Priority の不整合で failure | `ProjectConfigurationRepositoryTest`（不在 / 型違い / 別 Project 所属 / 表示名違い / ID 未設定 / 別 Project 返却）、`TerrariaDoctorCommandTest` の各 failure ケース |
| Issue list pagination が全ページを取得 | `IssueListPaginatorTest::it_fetches_every_page_not_just_the_first`（100+100+37=237、offset 0/100/200、`projectId[]` と `count=100` を検証）、`it_requests_another_page_when_a_page_is_exactly_full` |
| 429 / 5xx / timeout を成功扱いしない | `BacklogClientTest` の 429 / 5xx / timeout / 401 / 403 / 404 / 非 JSON、`IssueListPaginatorTest` の後続ページ 5xx・429・timeout |
| API Key がログや command output に出ない | `BacklogClientTest::it_never_leaks_the_api_key_in_exception_messages`（cURL メッセージに Key が混入した最悪ケースを含む）、`it_never_leaks_the_api_key_into_logs`、`TerrariaDoctorCommandTest::it_never_prints_the_api_key` ほか |
| Backlog API error を空結果として扱わない | `IssueListPaginatorTest::it_does_not_treat_a_server_error_on_the_first_page_as_zero_results` / `it_does_not_return_a_partial_result_when_a_later_page_fails`、`ProjectConfigurationRepositoryTest` の 429 / 500 ケース |

テストが骨抜きでないことを、実装を一時的に壊して確認した。

- 429 / 5xx の分岐を無効化 → 5 tests 失敗
- pagination を1ページで打ち切り → 5 tests 失敗
- `SecretRedactor` の置換を無効化 → 2 tests 失敗

### 未対応事項 / 申し送り

- **Task 02 / Task 04 が所有する設定への依存**: doctor は `config('terraria.allowed_world_keys')` / `config('terraria.collection_chest_name')` / `config('terraria.supported_runtime')` / `config('item_catalog.base_path')`（任意）を **読むだけ** とし、本 Task では `bridge/config/terraria.php` を作成していない。config が丸ごと存在しない場合も例外を出さず NG として報告する。キー名が Task 02 / 04 の実装と異なる場合は `RuntimeConfigurationChecker` 側を合わせる。
- Item catalog のパスは Task 04 実装後に `terraria.item_catalog_path` → `item_catalog.base_path` へ寄せた（評価側 `AchievementServiceProvider` と同じ config を読む）。未設定時は `<repo>/contracts/terraria/<version>/items.json` を参照する。
- Registry create / update、Mapping 完了更新、Achievement 判定は Task 05 / 06 のスコープとして未実装。`BacklogClient`（`get()` のみ公開）は write 用メソッドを持たない。Task 05 で `post()` / `patch()` を同じ例外方針で追加する。
- docs/design.md §13.3 の「短い transient retry を1回まで」は任意項目のため未実装。ゲーム側を待たせないことを優先し、失敗は次の periodic / manual reconciliation に委ねる。
- 後続 Task が使う interface:
  - `BacklogClient::get(string $path, array $query = []): BacklogResponse`（失敗は `BacklogApiException` 系）
  - `IssueListPaginator::fetchAll(int $projectId, array $filters = []): list<array>`
  - `ProjectConfigurationRepository::resolve(): ProjectConfiguration` / `verify(): ConfigurationReport`
