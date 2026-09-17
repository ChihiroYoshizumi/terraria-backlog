<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\EvaluateAchievements;
use App\Application\EvaluateAchievementsInput;
use App\Domain\Achievement\Achievement;
use App\Domain\ItemCatalog\ItemCatalogRepository;
use Tests\TestCase;

/**
 * AchievementServiceProvider の束縛と config/item_catalog.php の既定パスが、
 * リポジトリ同梱の contracts/terraria/<version>/items.json を指していることを確認する。
 *
 * DB は使わない (docs/design.md §2.1)。
 */
final class AchievementEvaluationWiringTest extends TestCase
{
    private const string VERSION = '1.3.0.8';

    public function test_container_resolves_the_evaluator_with_the_bundled_catalog(): void
    {
        $catalog = $this->app->make(ItemCatalogRepository::class)->forVersion(self::VERSION);

        $this->assertSame(self::VERSION, $catalog->terrariaVersion);
        $this->assertSame(999, $catalog->get(2)?->maxStack);

        $service = $this->app->make(EvaluateAchievements::class);

        $achievements = $service->evaluate(new EvaluateAchievementsInput(
            worldKey: 'terraria:123456789',
            terrariaVersion: self::VERSION,
            flags: ['downedBoss1' => true, 'downedBoss2' => true],
            collectionChests: [[
                'x' => 1,
                'y' => 2,
                'name' => 'BACKLOG_COLLECTION',
                'items' => [
                    ['type' => 1326, 'stack' => 1],
                    ['type' => 1326, 'stack' => 1],
                    ['type' => 2, 'stack' => 9999],
                ],
            ]],
        ));

        $this->assertSame(
            ['boss:eye_of_cthulhu', 'item:1326'],
            Achievement::keyStrings($achievements),
        );
    }
}
