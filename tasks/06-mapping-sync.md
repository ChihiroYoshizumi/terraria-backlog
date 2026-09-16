# Task 06: Mapping Repositoryと課題同期

## 目的
Backlog の攻略課題 Mapping を安全に読み取り、達成済み Registry に対応する未完了課題だけを完了させる。

## スコープ
- Snapshot-scoped Mapping scan/index
- Mapping 有効条件
  - Project ID exact match
  - Record Type null/空文字
  - 未完了
  - World Key あり
  - Terraria Key あり
- unknown non-empty Record Type は ignore + log
- incomplete/invalid Mapping は ignore + diagnostic log
- 同一 Achievement の複数課題対応
- 完了前の current issue 再取得と exact Mapping 再確認
- 既に完了なら PATCH しない
- Registry completed set との in-memory join
- reopen された課題は次回 reconciliation で再完了

## 依存関係
- Task 03
- Task 05

## 仕様・設計参照
- `docs/spec.md` §5.3, §8
- `docs/specs/backlog-mapping.md`
- `docs/specs/backlog-sync.md`
- `docs/design.md` §11, §12, §20, §21

## 対象 AC
- AC-06
- AC-07
- AC-10
- AC-14
- AC-15
- AC-19

## テスト
- 未達成 Mapping は更新しない
- Registry 達成後のみ完了
- post-hoc Mapping を検出可能
- same Achievement -> multiple issues を全て完了
- mapping-less issue ignored
- partial/invalid Mapping ignored
- unknown Record Type ignored
- completed general/training issue ignored
- Mapping 解除後は更新しない
- reopened issue は再完了

## 完了条件
- AC-06/07/10/14/15/19 の Mapping 側要件がテストで追跡できる
- 一般課題を推測で更新しない
