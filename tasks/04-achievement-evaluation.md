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

## 実装状況

- status: completed
- 実施日: 2026-09-17
- 対応バージョン: **Terraria 1.3.0.8 / TShock 4.3.13**（正本は `docs/design.md` §2.3）

### 実施内容

- `bridge/app/Domain/Achievement/`
  - `AchievementPrefix`（`boss` / `world` / `item`）、`AchievementKey`（Value Object。`boss()` / `world()` / `item(int)` / `fromString()`）、`Achievement`（key + 診断 metadata）、`InvalidAchievementKey`。
  - `WorldFlagEvaluator`: `docs/design.md` §8.1 の対応表を唯一の判定表として保持する。`true`（JSON boolean）のフラグだけを Achievement にし、表外のフラグは無視する。`downedBoss2` と `boss:wall_of_flesh` は表に存在しない。
- `bridge/app/Domain/ItemCatalog/`
  - `JsonFileItemCatalogRepository`: `<base>/<terrariaVersion>/items.json` を読む version-pinned reader。宣言 `terrariaVersion` が要求 version と一致しない場合・不在・破損・id 重複は `ItemCatalogUnavailable`（fail closed）。別 version へのフォールバックはしない。version 文字列はディレクトリ名として安全な形のみ許可する。
  - `ItemEntryValidator` / `ItemValidationResult` / `ItemRejectionReason`: `docs/design.md` §6.4 の Item 単位 validation（entry が object / `type` が JSON integer / catalog に存在 / `stack` が JSON integer / `1 <= stack <= maxStack`）。型 coercion はしない。
- `bridge/app/Application/`
  - `EvaluateAchievements`: World flag → Item の順で `Achievement[]` を返す。不正 Item は `item.invalid_skipped`（world key / chest index / chest 座標 / slot / 理由）を warning ログに出してその Item だけ無視し、同 Snapshot の valid Item / Boss / World 評価を継続する。同一 Item type は 1 Achievement に正規化し、Item Achievement は id 昇順で返す。
  - `EvaluateAchievementsInput`: Task 02 の Snapshot DTO へ依存しないための最小入力型（`worldKey` / `terrariaVersion` / `flags` / `collectionChests` / `requestId`）。`fromValidatedSnapshot(array)` で validated Snapshot 配列から組み立てる。`reason` は読まない。
- `bridge/config/item_catalog.php`（`base_path` / `TERRARIA_ITEM_CATALOG_PATH`）、`bridge/app/Providers/AchievementServiceProvider.php`（`bridge/bootstrap/providers.php` に登録）。
- `contracts/terraria/1.3.0.8/items.json` を実データへ拡張（下記）。

### Item catalog の収録範囲と選定基準

- 収録件数: **3601 件（Item ID 1〜3601 の全件）**。`ItemID.Count = 3602` で ID 0 は「アイテム無し」なので除外した。負値 ID（`ItemID` の Phasesaber / Copper〜Platinum 系の旧 ID エイリアス）はチェスト内の実 Item として出現しないため除外した。上限の絞り込みは行っておらず、攻略課題の有無で限定していない（`docs/spec.md` §5.1「有効な Terraria Item ID 全般を記録可能とする」に合わせた）。
- 出典（一次情報）: `scripts/setup-tshock.sh` が取得する **TShock 4.3.13 同梱の `.tshock-server/TerrariaServer.exe`（Terraria 1.3.0.8）そのもの**。Wiki 等の二次情報は使っていない。
- 抽出手順（再現方法）:
  1. `./scripts/setup-tshock.sh` で `.tshock-server/TerrariaServer.exe` を展開する。
  2. Mono（`brew install mono` 等）を用意し、`TerrariaServer.exe` を参照する小さなコンソールプログラムを `mcs -r:TerrariaServer.exe` でビルドする。
  3. そのプログラムで `Terraria.ID.ItemID.Count` まで `new Terraria.Item().SetDefaults(type, noMatCheck: true)` を実行し、`type` / `maxStack` / `name` を出力する。`noMatCheck: true` は Recipe 依存の `checkMat()` を避けるためで、`maxStack` と `name` には影響しない。一部の case が `Main.player[Main.myPlayer]` の色を参照するため、事前に `Terraria.Main.player` の空要素を `new Player()` で埋める。
  4. 出力を id 昇順で `{ "id", "name", "maxStack" }` に整形して `contracts/terraria/1.3.0.8/items.json` に書き出す。
  - 実行時に `SetDefaults` を通しているため、`ResetStats` の既定 `maxStack = 1`、`if (dye > 0) maxStack = 99`、`if (createTile == 19) maxStack = 999`、`type 1803..1807 -> SetDefaults(1533..1537)` といった後処理もすべて反映済み。`name` は `SetDefaults` 末尾で `Lang.itemName(netID)` により上書きされる値（英語表示名）。
- 1.3.0.8 であることの確認: ブロック類の `maxStack` が **999**（1.4.4 の 9999 ではない）、Copper/Silver/Gold Coin = 100・Platinum Coin = 999、Mana Potion = 75、Light Disc = 5、Bananarang = 10、Celestial Sigil = 20 など、1.3 系固有の値になっている。Task 01 のプレースホルダー 3 件（Dirt Block 2/999、Stone Block 3/999、Rod of Discord 1326/1）とも完全一致する。
- 後続で版を増やす場合: `contracts/terraria/<新 version>/items.json` を同じ手順で追加し、`docs/design.md` §2.3 の更新対象一覧に従って他の参照も更新する。Bridge 側は runtime version と一致する catalog しか読まないため、旧 version のファイルはそのまま残してよい。

### 検証結果

- `cd bridge && ./vendor/bin/phpunit`: **123 tests / 226 assertions すべて pass**（うち Task 04 追加分 121）。DB_* 未設定・DB 未使用。
  - `composer test`（= `php artisan test`）も `{"result":"passed","tests":123,"passed":123}` を出力するが、**このリポジトリでは `php artisan test` が pass 時でも exit code 1 を返す**。Task 04 以前から（`--filter ExampleTest` だけでも）再現する既存挙動で、本 Task の変更とは無関係。exit code を見る用途では `./vendor/bin/phpunit`（exit 0）を使うこと。
- `cd bridge && ./vendor/bin/pint --test`: passed。
- `cd contracts && npm run validate`: 全 OK（items catalog が `items-v1.schema.json` に適合し、id 重複なし）。
- テストが骨抜きでないことの確認（mutation 手動確認、いずれも実施後に元へ戻した）:
  - 対応表に `downedBoss2 -> boss:eater_of_worlds` を足す → 3 tests 失敗。
  - `hardMode` から `boss:wall_of_flesh` を生成する → 7 tests 失敗。
  - Item type の重複排除を外す → 2 tests 失敗。

### テスト（tasks の「テスト」8 項目との対応）

| 項目 | テスト |
| --- | --- |
| 全対応 World flag -> Achievement Key | `WorldFlagEvaluatorTest::test_each_supported_flag_maps_to_its_achievement_key` / `test_all_flags_true_yields_exactly_the_table` / `test_supported_flags_match_the_design_table` |
| ambiguous/shared flag から個別 Boss を推測しない | `WorldFlagEvaluatorTest::test_downed_boss2_never_produces_an_individual_boss_achievement` / `EvaluateAchievementsTest::test_shared_flags_never_produce_individual_bosses` |
| hardMode から Wall of Flesh を生成しない | `WorldFlagEvaluatorTest::test_hard_mode_never_produces_wall_of_flesh` |
| unknown Item ID を skip | `ItemEntryValidatorTest::test_unknown_item_id_is_skipped` |
| string/float `type` を skip | `ItemEntryValidatorTest::test_non_integer_type_is_skipped` |
| zero/negative/string/float/maxStack 超過 `stack` を skip | `ItemEntryValidatorTest::test_invalid_stack_is_skipped` / `test_max_stack_is_looked_up_per_item` |
| invalid Item と同居する valid Item/Boss/World を継続評価 | `EvaluateAchievementsTest::test_invalid_items_do_not_stop_the_rest_of_the_snapshot` |
| 同一 Item type の重複を1件へ正規化 | `EvaluateAchievementsTest::test_duplicate_item_types_collapse_into_one_achievement` / `test_slot_layout_does_not_change_the_result` |

その他に、Achievement Key の形式、catalog reader の fail closed / version pinning / 実カタログの 1.3.0.8 実値、`item.invalid_skipped` の診断内容、`reason` 非依存、Service Provider の結線を検証している。

### 未対応事項 / 申し送り

- `EvaluateAchievements` は Task 02 の Snapshot DTO へ依存していない。Task 07 の統合で `EvaluateAchievementsInput::fromValidatedSnapshot()`（配列入力）または同名コンストラクタへ接続する。
- Registry 保存 / Mapping 同期 / ACK 判定 / C# 側判定は本 Task のスコープ外。
- `event:` prefix は `AchievementPrefix` に用意していない。`docs/spec.md` §5.1 では予約されているが、`docs/design.md` §8 に再判定可能な Event フラグの対応表が無いため evaluator は生成しない。対象イベントが決まった時点で prefix と対応表を追加する。
