# Task 07: Reconciliation・通知/ACK・障害復旧

## 目的
Snapshot 処理全体を Application 層でつなぎ、startup / periodic / manual / collection_change の各契機で同じ正本から再照合できるようにする。

## スコープ
- `ProcessWorldSnapshot` orchestration
- Registry/Mapping scan -> Achievement 評価 -> Registry ensure -> Mapping 同期の順序
- startup / periodic / manual で Registry/Mapping を再取得
- manual Snapshot で post-hoc Mapping を即時反映
- `collection_change` で Registry 保存確認済み Item だけ player ACK を生成
- response は PHP 決定済み `notifications` のみ
- Backlog/PHP 障害中は成功 ACK を出さない
- 復旧後 periodic/manual reconciliation で初めて Registry 保存が確認できた場合:
  - 過去の操作 Player を追跡・復元しない
  - player 個人への復旧 ACK を保証しない
  - server console 向けの一般的な recovery notification のみ生成する
  - 例: `[Backlog] 復旧後の再同期が完了しました。`
  - Item 名や元 Player の特定を要件にしない
- 失敗後の player ACK context は永続化しない
- 構造化 logging

## 依存関係
- Task 02
- Task 04
- Task 05
- Task 06

## 仕様・設計参照
- `docs/spec.md` §8, §9, §10
- `docs/specs/reconciliation.md`
- `docs/specs/backlog-sync.md`
- `docs/design.md` §12, §14, §15, §17, §18

## 対象 AC
- AC-05
- AC-06
- AC-08
- AC-09
- AC-10
- AC-11
- AC-17
- AC-18

## テスト
- periodic/manual で post-hoc Mapping を反映
- Registry 保存失敗時は player ACK なし
- 復旧後 reconciliation 成功時は server recovery notification のみ
- 過去 Player への ACK を復元しない
- Mapping 更新失敗後も次回同期で完了
- invalid Item があっても Boss/World/Mapping 再照合を継続
- completed general issue は更新しない
- repeated reconciliation が不要な duplicate write を生まない

## 完了条件
- AC-09/10/18 の障害復旧経路が Feature Test で再現される
- DB/Outbox なしの復旧範囲を超えた保証を追加していない
