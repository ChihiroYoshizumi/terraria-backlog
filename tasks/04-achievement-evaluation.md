# Task 04: Achievement評価と Item catalog validation を実装する

## 目的
Terraria の raw state から PHP 側で Achievement 候補を安全に生成し、再判定可能な事実だけを扱う。

## 依存関係
- Task 01 完了後
- Task 02/03 と並行可能

## 参照
- `docs/spec.md` §5.1, §6, §7, AC-03, AC-04, AC-08, AC-17
- `docs/specs/collection-chest.md`
- `docs/specs/world-progress.md`
- `docs/design.md` §6.4, §8

## 対象 AC
- AC-03
- AC-04
- AC-08
- AC-17

## 実装すること

### 1. AchievementKey / Achievement 型を実装する
- Achievement Key を string のまま散らさず Value Object 化する。
- prefix は `boss:`, `world:`, `item:` を扱う。
- Adapter 側にはこの型を作らない。

### 2. World flag evaluator を実装する
`docs/design.md` §8.1 の対応表どおりに raw flag から Achievement を生成する。

対象:
- Eye of Cthulhu
- Skeletron
- Hardmode
- The Destroyer
- The Twins
- Skeletron Prime
- Plantera
- Golem
- Lunatic Cultist
- Moon Lord

禁止:
- `downedBoss2` から Eater of Worlds / Brain of Cthulhu を個別推測しない。
- `hardMode` から `boss:wall_of_flesh` を生成しない。

### 3. version-pinned Item catalog reader を実装する
- `contracts/terraria/<version>/items.json` を読み込む。
- Item ID -> name / maxStack を引けるようにする。
- runtime Terraria version と異なる catalog を暗黙利用しない。
- catalog 不在は fail closed の設定エラーとして扱う。

### 4. Item 単位 validation を実装する
各 Item について以下を満たす場合だけ Achievement 候補にする。
- Item entry が object
- `type` が JSON integer
- catalog に `type` が存在
- `stack` が JSON integer
- `1 <= stack <= catalog[type].maxStack`

不正 Item は:
- Snapshot 全体を失敗させない
- `item.invalid_skipped` をログ
- その Item だけ無視
- 同 Snapshot の valid Item / Boss / World 評価を続行

### 5. Item Achievement を生成する
valid Item ごとに:

```text
item:<item.type>
```

を生成する。

- 同一 Item type が複数 slot/chest に存在しても1 Achievementに正規化する。
- item name や chest 座標は診断 metadata として扱い、Achievement の一意性には使わない。

### 6. EvaluateAchievements service を作る
- validated `WorldSnapshot` を受け取る。
- World flags + valid Items を評価する。
- `Achievement[]` を返す。
- reason (`periodic` など) によって達成判定を変えない。

## 想定変更ファイル
- `bridge/app/Domain/Achievement*`
- `bridge/app/Domain/ItemCatalog*`
- `bridge/app/Application/EvaluateAchievements*`
- `bridge/tests/Unit/**`
- `contracts/terraria/**`

## 実装しないこと
- Registry 保存
- Mapping 同期
- ACK 判定
- C# 側 Achievement 判定

## テスト
- 全対応 World flag -> Achievement Key
- ambiguous/shared flag から個別 Boss を推測しない
- hardMode から Wall of Flesh を生成しない
- unknown Item ID を skip
- string/float `type` を skip
- zero/negative/string/float/maxStack超過 `stack` を skip
- invalid Item と同居する valid Item/Boss/World を継続評価
- 同一 Item type の重複を1件へ正規化

## 完了条件
- PHP の evaluator だけが Achievement Key を生成する。
- Task 05 が `Achievement[]` をそのまま Registry 入力に使える。
- AC-03/04/08/17 の判定ロジックが Unit Test で追跡できる。