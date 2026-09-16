# Task 05: Achievement Registry Repositoryと冪等性

## 目的
Backlog を Achievement Registry として安全に使い、重複・timeout・並行実行でも誤登録/誤ACKを防ぐ。

## スコープ
- Snapshot-scoped Registry scan/index
- Project ID + Record Type + World Key + Terraria Key の exact match
- `ensureRegistered`
  - 0件: create -> done -> verify
  - 1件完了: already_registered
  - 1件未完了: done -> verify
  - 複数 + 完了あり: logical already_registered
  - 複数 + 完了0: fail closed
- create/update 応答不明時の re-search
- `world_key + achievement_key` 単位 cross-process `flock`
- lock 範囲を latest lookup〜write〜verify まで含める
- registryIndex の局所更新

## 依存関係
- Task 03
- Task 04

## 仕様・設計参照
- `docs/spec.md` §5.2, §8
- `docs/specs/achievement-registry.md`
- `docs/design.md` §10, §20, §21

## 対象 AC
- AC-03
- AC-05
- AC-11
- AC-12
- AC-13

## テスト
- none -> create/done/verify
- existing done -> no write
- existing incomplete -> repair
- create timeout -> re-search
- duplicate incomplete -> no write / failed
- duplicate with completed -> logical success without destructive cleanup
- concurrent requests are serialized by shared file lock
- Registry query/final match are Project exact

## 完了条件
- AC-11/12 の冪等性・不明状態 fail-closed を満たす
- Registry 保存確認前に成功扱いしない
