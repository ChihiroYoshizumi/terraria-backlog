# Task 04: Achievement評価とItem catalog validation

## 目的
raw Terraria state から PHP 側で Achievement 候補を生成し、再判定可能な事実だけを扱う。

## スコープ
- World flag -> Achievement Key 変換
- MVP 対象 Boss/World 進捗のみ実装
- Eater/Brain の推測禁止
- Wall of Flesh を hardMode から独立 Achievement として推測しない
- version-pinned Item catalog reader
- Item ID / stack の item-local validation
- 不正 Item は skip/log し、他 Achievement 評価を継続
- 同一 Item type の重複候補を 1 Achievement へ正規化

## 依存関係
- Task 01

## 仕様・設計参照
- `docs/spec.md` §5.1, §6, §7
- `docs/specs/collection-chest.md`
- `docs/specs/world-progress.md`
- `docs/design.md` §6.4, §8

## 対象 AC
- AC-03
- AC-04
- AC-08
- AC-17

## テスト
- 全対応 flag の Key 変換
- shared/ambiguous flag から個別 Boss を推測しない
- unknown Item ID / string / float / zero / maxStack超過を skip
- 不正 Item と同居する valid Item/Boss/World 評価を継続
- 同一 Item が複数 slot/chest にいても候補は 1 つ

## 完了条件
- Achievement 判定が Adapter へ漏れていない
- AC-03/04/08/17 に必要な判定が unit test で追跡できる
