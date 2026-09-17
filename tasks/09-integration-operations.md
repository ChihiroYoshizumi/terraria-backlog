# Task 09: 統合テスト・運用手順・Smoke Test を整備する

## 目的
AC-01〜AC-19 を実装結果へ trace し、実 Backlog / TShock 環境で安全に導入・確認できる状態にする。

## 依存関係
- Task 01〜08 完了後

## 参照
- `docs/spec.md` §11 AC-01〜AC-19
- `docs/specs/*`
- `docs/design.md` §22〜§26

## 対象 AC
- AC-01〜AC-19 すべて

## 実装すること

### 1. AC traceability 表を作る
README または専用 docs に、AC-01〜AC-19 それぞれについて以下を1行で追跡できる表を作る。
- 対象 AC
- 実装箇所
- 自動テスト名 / Manual Acceptance 手順
- 関連 Task

「テスト済み」の一言ではなく、具体的な test class / test name / 手順へリンクできる形にする。

### 2. PHP Feature Test の不足分を補う
最低限、以下を end-to-end に近い形で HTTP Fake を使って確認する。
- Snapshot auth / world / runtime validation
- Registry none -> create -> done -> verify
- duplicate Registry / timeout / re-search
- Mapping sync
- post-hoc Mapping periodic/manual
- Backlog 429 / 5xx / timeout
- Registry 保存失敗時 ACK なし
- recovery server notification
- invalid Item が他 reconciliation を止めない
- completed general/training issue を変更しない
- World A/B 分離

### 3. Concurrency Test を整備する
同一 `world_key + achievement_key` への並行処理を発生させ、shared `flock` により lookup-create-verify が直列化されることを確認する。

単純 Unit Test で mutex mock するだけでなく、可能な範囲で別 process/worker 相当の競合を再現する。

### 4. Contract Test を整備する
`contracts/examples` を使い、以下を両側で確認する。
- C# serializer output が request schema に適合
- PHP request validation が同 schema と整合
- runtime fields
- collectionChestName
- Item-local invalid behavior
- response が notification-only

### 5. Adapter Test の不足分を補う
最低限:
- runtime gate
- snapshot builder
- world key default/override
- chest filter
- debounce / player aggregation
- periodic vs pending collection coalescing
- single-flight
- notification rendering
- Adapter に Achievement/Backlog domain dependency がない

### 6. セットアップ手順を書く
導入者が README / operation doc だけで起動できるよう、以下を具体的に記載する。
- PHP / Composer install
- Bridge 起動方法
- Adapter build / 配置方法
- TShock plugin 配置先
- 必須環境変数
- Adapter config
- `php artisan terraria:doctor`
- `/backlog sync`

### 7. Backlog 初期設定手順を書く
`TRAINING_YOSHIZUMI` で必要な設定確認を手順化する。
- 3 Custom Field の確認
- Field ID 取得
- Done Status ID
- Registry Issue Type ID
- Priority ID
- API Key 権限
- Project Key 固定確認

値は実環境から取得し、ドキュメント例を固定値として使わない。

### 8. Terraria/TShock compatibility と Smoke Test 手順を書く

MVP の対応バージョンは次で固定する（正本は `docs/design.md` §2.3）。

- 対応 Terraria version: **1.3.0.8**
- 対応 TShock version: **4.3.13**
- client は **Vanilla Terraria 1.3.0.8**。tModLoader / 専用 client MOD は要求しない。

記載すること:
- 上記バージョンと、client 側で 1.3.0.8 を選ぶ手順
- SupportedVersionMatrix 更新方法
- 実 server で plugin load 成功確認
- Vanilla client 接続確認
- Collection Chest 変更の実機確認: **drag / shift / quick stack の3経路すべて**
- 上記3経路それぞれで `collection_change` の player attribution（`trigger.playerNames`）が期待どおりになることの確認。取得できない経路がある場合は、その経路と理由を明記する（推測値で埋めない）
- world progression
- startup/periodic/manual sync

運用範囲の前提:
- MVP は**ローカル利用前提**とする。TShock Server を public Internet へ公開することは対象外であり、公開運用向けの手順（port forwarding、外部公開時の認証・DDoS・アカウント保護等）は本 Task の範囲に含めない。

### 9. Manual Acceptance 手順を作る
実 `TRAINING_YOSHIZUMI` を使う手動試験を、既存研修課題を壊さないよう専用 Test Issue / Mapping 前提で手順化する。

必須シナリオ:
- Mapping 無し Item 納品 -> Registry + ACK
- Item 取り出し後も Registry 維持
- 後付け Mapping -> periodic/manual で完了
- Backlog 停止 -> ACK 無し -> 復旧 -> server recovery notification
- Registry 保存後 Mapping 更新だけ失敗 -> 次回 sync 完了
- duplicate Registry
- reopen
- invalid mapping
- World A/B 分離
- completed training/general issue untouched
- mapping-less issue untouched

### 10. 障害対応手順を書く
最低限:
- `terraria:doctor` の確認
- Bridge log の見るべき operation
- 429 / 5xx / timeout 時の確認
- duplicate Registry 診断
- manual `/backlog sync`
- runtime mismatch
- Item catalog mismatch

API Key や Bearer Token を貼り付ける診断手順は禁止する。

### 11. 最終仕様逸脱チェックを行う
実装全体を見て以下が紛れ込んでいないか確認する。
- DB
- Redis/永続 Queue
- Outbox/Event Store
- C# 側 Achievement 判定
- Backlog -> Terraria 逆同期
- 一過性イベント無損失保証
- spec/design に無い自動修復や推測

見つかった場合は「便利だから」で残さず、正本との差異として扱う。

## 想定変更ファイル
- `bridge/tests/**`
- `adapter/Tests/**`
- `contracts/examples/**`
- `README.md`
- `docs/operations*.md` 等

## 完了条件
- AC-01〜AC-19 すべてに「実装箇所 + 検証方法」が存在する。
- 実環境 Smoke Test / Acceptance を手順どおり実行できる。
- MVP 導入者が README/運用docだけでセットアップ・診断・手動再同期できる。
- 仕様外保証が追加されていない。