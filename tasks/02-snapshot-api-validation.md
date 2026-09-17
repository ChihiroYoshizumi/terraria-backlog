# Task 02: Snapshot API・認証・World/Runtime validation を実装する

## 目的
Adapter → PHP の信頼境界を確立し、入力を fail closed で検証したうえで後続 Application 処理へ渡せるようにする。

## 依存関係
- Task 01 完了後

## 参照
- `docs/spec.md` §4, §5.1, §6, §9, AC-13, AC-16, AC-17
- `docs/specs/world-identity.md`
- `docs/specs/operations-security.md`
- `docs/design.md` §5, §6, §16

## 対象 AC
- AC-13
- AC-16
- AC-17

## 実装すること

### 1. Snapshot endpoint を追加する
以下を実装する。

```http
POST /api/v1/worlds/{worldKey}/snapshots
Authorization: Bearer <adapter-token>
Content-Type: application/json
```

- route / controller / request DTO を追加する。
- Bearer Token は PHP Bridge 設定から取得する。
- 未認証・不正 token は処理開始前に reject する。

### 2. Snapshot v1 request を DTO 化する
`contracts/snapshot-v1.schema.json` と `docs/design.md` §6.2 に合わせて少なくとも以下を扱う。
- `schemaVersion`
- `requestId`
- `reason`
- `observedAt`
- `runtime`
- `world`
- `collectionChestName`
- `flags`
- `collectionChests`
- `trigger.playerNames`

Controller 内で生配列を直接使い回さず、後続 Application が安全に扱える DTO/Value Object へ変換する。

### 3. request-level validation を実装する
以下は Snapshot 全体を reject する。
- `schemaVersion !== 1`
- `requestId` が UUID でない
- `reason` が許可値以外
- runtime pair が `TERRARIA_SUPPORTED_RUNTIME` と不一致
- URL `worldKey` と payload `world.key` が不一致
- `world.key` が allowlist 外
- `terrariaWorldId` が整数でない
- `collectionChestName` が PHP 設定と不一致
- `collectionChests` / chest / flags のコンテナ構造不正
- Request Body / Chest / Item 件数が設定上限超過
- payload から URL / Project Key / Issue Key / Custom Field ID / Status ID など外部操作先を指定しようとする入力

HTTP status は既存 design の意味を崩さない範囲で 4xx を使い分ける。runtime mismatch は design に合わせて 409 とする。

### 4. Item-level validation の境界を分離する
この Task では Item catalog による完全判定は Task 04 に任せるが、request-level validation と混ぜない構造にする。
- `items[]` 内の Item 値不正が Snapshot 全体 422 にならない設計にする。
- Item ごとの validation 結果を Task 04 の Achievement evaluator に渡せる構造にする。

### 5. response contract を固定する
正常 response は notification-only とする。

```json
{
  "requestId": "...",
  "worldKey": "terraria:123",
  "notifications": []
}
```

- Achievement Key を返さない。
- Registry result を返さない。
- Backlog Issue Key を返さない。
- Mapping result を返さない。

この Task 時点では Application 処理を stub/no-op にして `notifications: []` を返してよい。

### 6. 設定を追加する
少なくとも以下を `.env.example` / config に追加する。
- Adapter Bearer Token
- `TERRARIA_ALLOWED_WORLD_KEYS`
- `TERRARIA_SUPPORTED_RUNTIME`
- `TERRARIA_COLLECTION_CHEST_NAME`
- Request / Chest / Item 件数上限

## 想定変更ファイル
- `bridge/routes/**`
- `bridge/app/Http/**`
- `bridge/app/Domain/**` の World/Runtime DTO
- `bridge/config/**`
- `bridge/tests/Feature/**`
- `contracts/**` 必要な場合

## 実装しないこと
- Backlog API 呼び出し
- Registry create/update
- Mapping 同期
- Achievement Key 判定
- TShock hook

## テスト
- token 無し / 不正 token を reject
- world URL/payload mismatch を reject
- allowlist 外 world を reject
- unsupported runtime を reject
- malformed envelope を reject
- 件数上限超過を reject
- 禁止 field を操作先として信用しない
- invalid Item 値が存在しても request-level validation だけを理由に全体 reject しない
- response に Achievement Key / Issue Key / Mapping result が存在しない

## 完了条件
- 後続 Task は validated Snapshot DTO を受け取ればよい状態になっている。
- AC-13/16/17 の入力境界が Feature Test で追跡できる。
- 正本と矛盾する場合は独自解釈せず止める。
---

## 実装状況

- **status**: done
- **実施日**: 2026-09-17
- **ブランチ / PR**: `tasks/02-snapshot-api-validation`

### 実施内容

#### Endpoint / 認証

- `bridge/routes/api.php`: `POST /api/v1/worlds/{worldKey}/snapshots` を追加 (`api.v1.worlds.snapshots.store`)。`worldKey` は path segment として安全な文字に制限。
- `bridge/app/Http/Middleware/EnsureAdapterToken.php`: route middleware。`Authorization: Bearer` のみ受理し `hash_equals` で比較。未設定 token は「認証不要」と解釈せず `503` で fail closed。token 値は response にもログにも出さない。
- `bridge/app/Http/Controllers/Api/V1/SnapshotController.php`: raw body を受け取り parser へ渡すだけの薄い Controller。Parser / Processor は method injection（Laravel は Route ごとに controller instance を memo 化するため）。

#### Snapshot DTO (`bridge/app/Domain/Snapshot/`)

`WorldKey` / `WorldIdentity` / `RuntimeVersions` / `WorldFlags` / `CollectionChest` / `ObservedItem` / `SnapshotReason` / `WorldSnapshot` を追加。Controller は生配列を後続へ渡さない。

#### request-level validation (`SnapshotRequestParser`)

FormRequest を使わず raw body を `json_decode($body, false, ...)` で解析する。理由は design §6.4 の「型 coercion を行わない」を満たすため（Laravel の `integer` / `boolean` rule は文字列を受理する）。JSON object と JSON array も区別する。

検証順序は design §6.4 の箇条書き順。HTTP status は `SnapshotRejectionCode::httpStatus()` に集約した。

| 事象 | status |
| --- | --- |
| token 無し / 不正 token | 401 |
| Bridge 設定不備（token / supported runtime / chest name / allowlist 未設定） | 503 |
| Request Body バイト数上限超過 | 413 |
| JSON として読めない | 400 |
| runtime pair が `TERRARIA_SUPPORTED_RUNTIME` と不一致 | 409 (design §2.3) |
| URL と `world.key` の不一致 / allowlist 外 | 403 (design §5.2) |
| envelope 構造不正・件数上限超過・禁止 field | 422 |

禁止 field (design §16.3) は payload の全階層を走査し、キー名を「小文字化＋英数字以外を除去」して denylist と照合する。denylist は `config/terraria.php` の `forbidden_payload_keys`。

#### Item-level validation の境界

`ObservedItem` が Task 02 と Task 04 の境界。Task 02 は構造レベル（object か / `type`・`stack` が JSON integer か / `stack >= 1`）だけを判定し、結果を `ItemRejectionReason` の list として保持する。**不正 Item があっても Snapshot 全体は成立する**（design §18.3 / AC-17）。catalog 照合用の `TypeNotInCatalog` / `StackExceedsMaxStack` は enum に予約済みで、Task 04 は `ObservedItem::withRejection()` で理由を追加できる（immutable）。構造不正 Item は `item.invalid_skipped` として chest 座標つきで診断ログへ残す。

#### response contract

`SnapshotNotification` / `SnapshotResult` が `contracts/snapshot-response-v1.schema.json` と 1:1 対応。`audience=server` は `playerNames` を持たない。Controller が返すのは `requestId` / `worldKey` / `notifications` のみ。

Application 処理は `SnapshotProcessor` interface + `NoopSnapshotProcessor`（常に `notifications: []`）。後続 Task は `App\Application\ProcessWorldSnapshot` をこの interface として bind すれば差し替えられる（`SnapshotServiceProvider` は `bindIf`）。

#### 設定

`bridge/config/terraria.php` を新規作成し、`bridge/.env.example` に `# --- Task 02: Snapshot API ---` セクションを追記。`TERRARIA_ADAPTER_TOKEN` / `TERRARIA_ALLOWED_WORLD_KEYS` / `TERRARIA_SUPPORTED_RUNTIME`（既定 `4.3.13:1.3.0.8`）/ `TERRARIA_COLLECTION_CHEST_NAME` / 各種上限。秘密情報の実値はコミットしていない。

### 検証結果

| コマンド | 結果 |
| --- | --- |
| `cd bridge && composer test` | PASS（107 tests / 431 assertions、うち Snapshot 関連 105）。DB_* 未設定・`QUEUE_CONNECTION=sync` / `SESSION_DRIVER=array` / `CACHE_STORE=array` で成功 |
| `cd bridge && ./vendor/bin/pint --test` | PASS |
| `cd contracts && npm run validate` | PASS（5 件すべて OK） |

テスト対象（tasks のテスト項目 9 件をすべてカバー）:

| テスト項目 | テストクラス |
| --- | --- |
| token 無し / 不正 token を reject | `SnapshotAuthenticationTest` |
| world URL/payload mismatch を reject | `SnapshotWorldValidationTest` |
| allowlist 外 world を reject | `SnapshotWorldValidationTest` |
| unsupported runtime を reject | `SnapshotRuntimeValidationTest` |
| malformed envelope を reject | `SnapshotEnvelopeValidationTest` |
| 件数上限超過を reject | `SnapshotLimitTest` |
| 禁止 field を操作先として信用しない | `SnapshotInputTrustTest` |
| invalid Item 値でも全体 reject しない | `SnapshotItemBoundaryTest` |
| response に Achievement Key / Issue Key / Mapping result が無い | `SnapshotResponseContractTest` |
| 型 coercion しないこと / contract example の DTO 化 | `Tests\Unit\Snapshot\SnapshotRequestParserTest` |
| notification-only contract の VO | `Tests\Unit\Snapshot\SnapshotNotificationTest` |

### 未対応事項 / 後続 Task への申し送り

- Item catalog による完全判定（`type` の実在 / `stack <= maxStack`）は **Task 04**。`ItemRejectionReason` の予約 case と `ObservedItem::withRejection()` を使うこと。
- Achievement 判定 / Registry / Mapping / Backlog API 呼び出し / TShock hook は Task 02 のスコープ外。未実装。
- 通知生成は **Task 07**。`SnapshotProcessor` を実装した `App\Application\ProcessWorldSnapshot` を自 Provider で bind する（`SnapshotServiceProvider` は `bindIf` なので上書き可能）。
- `terraria:doctor`（**Task 03**）に `config('terraria')` の検証（token / supported runtime / allowlist / chest name が空でないこと）を追加すると design §2.3 / §18.4 と揃う。
- 既存の `bridge/tests/Feature/ExampleTest.php`（Task 01）は `.env` が無いと `APP_KEY` 未設定で失敗する。`composer setup` 実行後は全件 PASS。本 Task では手を入れていない。
