# Task 08: TShock Adapter

## 目的
TShock 上で Terraria の現在状態を観測し、薄い Adapter として Snapshot 送信と PHP 決定済み通知の表示だけを担う。

## スコープ
- runtime compatibility gate
- World Key 生成 / override
- startup Full Snapshot
- World State 取得
- Collection Chest 名 filter（設定可能）
- `ChestItemChange` + Quick Stack を dirty trigger として扱う
- 約500ms debounce 後に対象 Chest 全 slot を再取得
- collection trigger playerNames の重複排除/集約
- 60秒 periodic Full Snapshot
- `/backlog sync` + permission `terrariabacklog.sync`
- single-flight HTTP sender
- `collection_change` Snapshot を periodic で破棄しない coalescing
- pending player context は送信成功/失敗応答までメモリ保持、プロセス終了で消失可
- notification renderer は `audience` / `playerNames` / `message` をそのまま表示
- `audience=server` の recovery notification を console 表示

## 禁止事項
- Achievement Key を生成/解釈しない
- Backlog API を呼ばない
- Registry/Mapping/Issue Key を扱わない
- 永続 Queue/Outbox を持たない

## 依存関係
- Task 01
- Task 02

## 仕様・設計参照
- `docs/spec.md` §4, §6, §7, §9
- `docs/specs/collection-chest.md`
- `docs/specs/world-identity.md`
- `docs/specs/reconciliation.md`
- `docs/design.md` §2.2〜§2.3, §5〜§7, §14〜§15

## 対象 AC
- AC-01
- AC-04
- AC-08
- AC-09
- AC-18

## テスト
- supported/unsupported runtime gate
- WorldKey default/override
- configured chest name only 対象
- drag/shift/quick stack が最終 Chest state へ収束
- debounce + player aggregation
- periodic が pending collection trigger を破棄しない
- single-flight
- notification を再解釈せず指定宛先へ表示
- Adapter コードに Achievement/Issue Key 依存がない

## 完了条件
- Vanilla client のまま利用可能な TShock Plugin としてビルドできる
- AC-01/04/08/09/18 の Adapter 側要件が追跡できる
