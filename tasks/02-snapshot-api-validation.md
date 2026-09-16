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