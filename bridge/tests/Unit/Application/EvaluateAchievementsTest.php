<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\EvaluateAchievements;
use App\Application\EvaluateAchievementsInput;
use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\WorldFlagEvaluator;
use App\Domain\ItemCatalog\ItemCatalogUnavailable;
use App\Domain\ItemCatalog\ItemEntryValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\InMemoryItemCatalogRepository;
use Tests\Unit\Support\RecordingLogger;

/**
 * docs/design.md §8 / §6.4、docs/specs/collection-chest.md、AC-03 / AC-04 / AC-08 / AC-17。
 */
final class EvaluateAchievementsTest extends TestCase
{
    private const string VERSION = '1.3.0.8';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
    }

    private function service(): EvaluateAchievements
    {
        return new EvaluateAchievements(
            new InMemoryItemCatalogRepository(self::VERSION, [
                2 => ['Dirt Block', 999],
                3 => ['Stone Block', 999],
                71 => ['Copper Coin', 100],
                1326 => ['Rod of Discord', 1],
            ]),
            new WorldFlagEvaluator,
            new ItemEntryValidator,
            $this->logger,
        );
    }

    /**
     * @param  array<array-key, mixed>  $flags
     * @param  list<mixed>  $chests
     */
    private function input(array $flags = [], array $chests = [], string $version = self::VERSION): EvaluateAchievementsInput
    {
        return new EvaluateAchievementsInput(
            worldKey: 'terraria:123456789',
            terrariaVersion: $version,
            flags: $flags,
            collectionChests: $chests,
            requestId: '0199f136-9e36-7f41-b148-e5b4f384a321',
        );
    }

    /**
     * @param  list<mixed>  $items
     * @return array{x: int, y: int, name: string, items: list<mixed>}
     */
    private function chest(array $items, int $x = 120, int $y = 340): array
    {
        return ['x' => $x, 'y' => $y, 'name' => 'BACKLOG_COLLECTION', 'items' => $items];
    }

    public function test_evaluates_world_flags_and_items_together(): void
    {
        $achievements = $this->service()->evaluate($this->input(
            ['downedBoss1' => true, 'hardMode' => true],
            [$this->chest([['type' => 2, 'stack' => 10], ['type' => 1326, 'stack' => 1]])],
        ));

        $this->assertSame(
            ['boss:eye_of_cthulhu', 'world:hardmode', 'item:2', 'item:1326'],
            Achievement::keyStrings($achievements),
        );
    }

    /**
     * AC-03: 攻略課題があるかどうかに関係なく、valid な Item はすべて Achievement になる。
     */
    public function test_every_valid_item_becomes_an_achievement(): void
    {
        $achievements = $this->service()->evaluate($this->input([], [
            $this->chest([
                ['type' => 2, 'stack' => 1],
                ['type' => 3, 'stack' => 999],
                ['type' => 71, 'stack' => 100],
            ]),
        ]));

        $this->assertSame(['item:2', 'item:3', 'item:71'], Achievement::keyStrings($achievements));
    }

    /**
     * AC-04: 同一 Item type が複数 slot / 複数 chest にあっても 1 Achievement に正規化する。
     * item name や chest 座標は一意性に使わない。
     */
    public function test_duplicate_item_types_collapse_into_one_achievement(): void
    {
        $achievements = $this->service()->evaluate($this->input([], [
            $this->chest([
                ['type' => 2, 'stack' => 1, 'name' => 'Dirt Block'],
                ['type' => 2, 'stack' => 500, 'name' => 'Dirt Block'],
                ['type' => 2, 'stack' => 999, 'name' => 'muddy block'],
            ], x: 120, y: 340),
            $this->chest([
                ['type' => 2, 'stack' => 7],
                ['type' => 3, 'stack' => 1],
            ], x: 900, y: 12),
        ]));

        $this->assertSame(['item:2', 'item:3'], Achievement::keyStrings($achievements));
    }

    /**
     * AC-04: Quick Stack などで slot 構成が変わっても、最終内容が同じなら同じ達成になる。
     */
    public function test_slot_layout_does_not_change_the_result(): void
    {
        $spread = $this->service()->evaluate($this->input([], [
            $this->chest([['type' => 2, 'stack' => 1], ['type' => 2, 'stack' => 1], ['type' => 3, 'stack' => 1]]),
        ]));

        $merged = $this->service()->evaluate($this->input([], [
            $this->chest([['type' => 3, 'stack' => 5]]),
            $this->chest([['type' => 2, 'stack' => 2]], x: 999, y: 1),
        ]));

        $this->assertSame(['item:2', 'item:3'], Achievement::keyStrings($spread));
        $this->assertSame(Achievement::keyStrings($spread), Achievement::keyStrings($merged));
    }

    /**
     * docs/design.md §6.4: 不正 Item は Snapshot 全体を失敗させず、
     * 同 Snapshot の valid Item / Boss / World 評価を継続する。
     */
    public function test_invalid_items_do_not_stop_the_rest_of_the_snapshot(): void
    {
        $achievements = $this->service()->evaluate($this->input(
            ['downedBoss3' => true, 'downedMoonlord' => true],
            [
                $this->chest([
                    ['type' => 999999, 'stack' => 1],       // catalog 外
                    ['type' => '2', 'stack' => 1],          // string type
                    ['type' => 2.0, 'stack' => 1],          // float type
                    ['type' => 2, 'stack' => 0],            // stack 下限未満
                    ['type' => 2, 'stack' => -5],           // 負の stack
                    ['type' => 2, 'stack' => '3'],          // string stack
                    ['type' => 2, 'stack' => 3.5],          // float stack
                    ['type' => 1326, 'stack' => 2],         // maxStack 超過
                    [1, 2, 3],                              // object ではない
                    ['type' => 3, 'stack' => 1],            // これだけ valid
                ]),
            ],
        ));

        $this->assertSame(
            ['boss:skeletron', 'boss:moon_lord', 'item:3'],
            Achievement::keyStrings($achievements),
        );

        $skipped = $this->logger->withMessage('item.invalid_skipped');
        $this->assertCount(9, $skipped);
    }

    public function test_invalid_item_is_logged_with_world_and_chest_diagnostics(): void
    {
        $this->service()->evaluate($this->input([], [
            $this->chest([['type' => 1326, 'stack' => 5]], x: 4242, y: 777),
        ]));

        $skipped = $this->logger->withMessage('item.invalid_skipped');

        $this->assertCount(1, $skipped);
        $this->assertSame('warning', $skipped[0]['level']);

        $context = $skipped[0]['context'];
        $this->assertSame('terraria:123456789', $context['worldKey']);
        $this->assertSame(4242, $context['chestX']);
        $this->assertSame(777, $context['chestY']);
        $this->assertSame(0, $context['slot']);
        $this->assertSame(1326, $context['itemType']);
        $this->assertSame('stack_out_of_range', $context['reason']);
    }

    public function test_valid_items_are_not_logged_as_skipped(): void
    {
        $this->service()->evaluate($this->input([], [$this->chest([['type' => 2, 'stack' => 1]])]));

        $this->assertSame([], $this->logger->withMessage('item.invalid_skipped'));
    }

    /**
     * docs/design.md §8.2 / AC-17: ambiguous / shared flag から個別 Boss を推測しない。
     */
    public function test_shared_flags_never_produce_individual_bosses(): void
    {
        $achievements = $this->service()->evaluate($this->input([
            'downedBoss2' => true,
            'hardMode' => true,
        ]));

        $keys = Achievement::keyStrings($achievements);

        $this->assertSame(['world:hardmode'], $keys);
        $this->assertNotContains('boss:eater_of_worlds', $keys);
        $this->assertNotContains('boss:brain_of_cthulhu', $keys);
        $this->assertNotContains('boss:wall_of_flesh', $keys);
    }

    /**
     * 達成判定は reason に依存しない (docs/design.md §6.3)。
     *
     * @return list<array{0: string}>
     */
    public static function snapshotReasons(): array
    {
        return [['startup'], ['periodic'], ['collection_change'], ['world_change'], ['manual']];
    }

    #[DataProvider('snapshotReasons')]
    public function test_reason_does_not_change_the_evaluation(string $reason): void
    {
        $snapshot = [
            'schemaVersion' => 1,
            'requestId' => '0199f136-9e36-7f41-b148-e5b4f384a321',
            'reason' => $reason,
            'runtime' => ['adapterVersion' => '0.1.0', 'tshockVersion' => '4.3.13', 'terrariaVersion' => self::VERSION],
            'world' => ['key' => 'terraria:123456789', 'terrariaWorldId' => 123456789],
            'collectionChestName' => 'BACKLOG_COLLECTION',
            'flags' => ['downedBoss1' => true, 'downedPlantBoss' => true],
            'collectionChests' => [$this->chest([['type' => 2, 'stack' => 1], ['type' => 1326, 'stack' => 1]])],
        ];

        $achievements = $this->service()->evaluate(EvaluateAchievementsInput::fromValidatedSnapshot($snapshot));

        $this->assertSame(
            ['boss:eye_of_cthulhu', 'boss:plantera', 'item:2', 'item:1326'],
            Achievement::keyStrings($achievements),
        );
    }

    public function test_input_can_be_built_from_a_validated_snapshot_array(): void
    {
        $input = EvaluateAchievementsInput::fromValidatedSnapshot([
            'requestId' => 'abc',
            'runtime' => ['terrariaVersion' => '1.3.0.8'],
            'world' => ['key' => 'terraria:1'],
            'flags' => ['hardMode' => true],
            'collectionChests' => [$this->chest([])],
        ]);

        $this->assertSame('terraria:1', $input->worldKey);
        $this->assertSame('1.3.0.8', $input->terrariaVersion);
        $this->assertSame('abc', $input->requestId);
        $this->assertSame(['hardMode' => true], $input->flags);
        $this->assertCount(1, $input->collectionChests);
    }

    public function test_empty_snapshot_produces_no_achievements(): void
    {
        $this->assertSame([], $this->service()->evaluate($this->input()));
        $this->assertSame([], $this->service()->evaluate($this->input([], [$this->chest([])])));
    }

    public function test_malformed_chest_containers_are_ignored_without_failing(): void
    {
        $achievements = $this->service()->evaluate($this->input(['hardMode' => true], [
            ['x' => 1, 'y' => 2, 'name' => 'BACKLOG_COLLECTION'],   // items なし
            ['x' => 1, 'y' => 2, 'name' => 'BACKLOG_COLLECTION', 'items' => 'nope'],
            $this->chest([['type' => 3, 'stack' => 1]]),
        ]));

        $this->assertSame(['world:hardmode', 'item:3'], Achievement::keyStrings($achievements));
    }

    /**
     * catalog を読めない場合は fail closed。Item を無検証で通さない。
     */
    public function test_unknown_runtime_version_fails_closed(): void
    {
        $this->expectException(ItemCatalogUnavailable::class);

        $this->service()->evaluate($this->input(
            ['hardMode' => true],
            [$this->chest([['type' => 2, 'stack' => 1]])],
            version: '1.4.4.9',
        ));
    }

    public function test_item_metadata_is_diagnostic_only(): void
    {
        $achievements = $this->service()->evaluate($this->input([], [
            $this->chest([['type' => 1326, 'stack' => 1, 'name' => 'not the catalog name']], x: 5, y: 6),
        ]));

        $this->assertCount(1, $achievements);
        $this->assertSame('item:1326', $achievements[0]->keyString());
        $this->assertSame('Rod of Discord', $achievements[0]->metadata['itemName']);
        $this->assertSame(5, $achievements[0]->metadata['firstSeenChestX']);
        $this->assertSame(6, $achievements[0]->metadata['firstSeenChestY']);
    }
}
