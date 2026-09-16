# Task 02: Snapshot API・認証・World/Runtime validation

## 目的
Adapter→PHP 境界を確立し、信頼できない Snapshot を fail closed で拒否できるようにする。

## スコープ
- `POST /api/v1/worlds/{worldKey}/snapshots`
- Bearer Token 認証
- Snapshot v1 request/response DTO
- request-level validation
  - schemaVersion
  - requestId UUID
  - runtime supported pair
  - URL worldKey / payload world.key / allowlist 一致
  - terrariaWorldId 型
  - collectionChestName 設定一致
  - container shape / flags / size limit
  - 外部操作先 field の拒否
- Item-level validation の入口
- 不正 Item は Snapshot 全体を reject しない
- response は notification-only contract に固定

## 非スコープ
- Backlog Registry 書き込み
- Mapping 同期
- TShock hook

## 依存関係
- Task 01

## 仕様・設計参照
- `docs/spec.md` §4, §5.1, §6, §9
- `docs/specs/world-identity.md`
- `docs/specs/operations-security.md`
- `docs/design.md` §5, §6, §16

## 対象 AC
- AC-13
- AC-16
- AC-17

## テスト
- 未認証 => reject
- world mismatch / allowlist 外 => reject
- runtime mismatch => reject
- malformed envelope => reject
- invalid Item 値だけなら request は継続可能
- response に Achievement Key / Backlog Issue Key / Mapping 結果が存在しない

## 完了条件
- AC-13/16/17 の入力境界がテストで担保される
- C# 側へ Backlog 内部情報を漏らさない
