<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Mapping;

use App\Domain\Achievement\AchievementKey;
use App\Domain\Mapping\MappingIndex;
use App\Domain\Mapping\MappingIssue;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Snapshot-scoped Mapping Index の不変条件 (docs/design.md §11, §12 / AC-13, AC-14, AC-19)。
 */
final class MappingIndexTest extends TestCase
{
    private const PROJECT_ID = 4242;

    private const WORLD_A = 'terraria:111111111';

    private const WORLD_B = 'terraria:222222222';

    #[Test]
    public function it_groups_multiple_issues_under_the_same_achievement_key(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID, [
            $this->mapping(1, 'item:1326'),
            $this->mapping(2, 'item:1326'),
            $this->mapping(3, 'boss:king_slime'),
        ]);

        $this->assertSame(3, $index->count());
        $this->assertSame(['boss:king_slime', 'item:1326'], $index->achievementKeys());
        $this->assertCount(2, $index->forKey('item:1326'));
        $this->assertCount(2, $index->forKey(AchievementKey::item(1326)));
        $this->assertTrue($index->has('boss:king_slime'));
        $this->assertFalse($index->has('boss:skeletron'));
    }

    #[Test]
    public function it_refuses_mappings_from_another_world(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID);

        $this->expectException(InvalidArgumentException::class);

        $index->add($this->mapping(1, 'item:1326', worldKey: self::WORLD_B));
    }

    #[Test]
    public function it_refuses_mappings_from_another_project(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID);

        $this->expectException(InvalidArgumentException::class);

        $index->add($this->mapping(1, 'item:1326', projectId: 9999));
    }

    #[Test]
    public function it_refuses_completed_issues(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID);

        // 完了済みの攻略課題・研修課題は同期対象外 (AC-19)。index に入れない。
        $this->expectException(InvalidArgumentException::class);

        $index->add($this->mapping(1, 'item:1326', done: true));
    }

    #[Test]
    public function it_only_returns_issues_whose_achievement_is_registered(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID, [
            $this->mapping(1, 'item:1326'),
            $this->mapping(2, 'item:1326'),
            $this->mapping(3, 'boss:king_slime'),
        ]);

        $matched = $index->forCompletedAchievements(['item:1326']);

        // Registry 未達成の boss:king_slime は候補にすらならない (AC-07)。
        $this->assertSame([1, 2], array_map(static fn (MappingIssue $issue): int => $issue->issueId, $matched));
    }

    #[Test]
    public function it_does_not_return_the_same_issue_twice(): void
    {
        $index = new MappingIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID, [
            $this->mapping(1, 'item:1326'),
        ]);

        $matched = $index->forCompletedAchievements(['item:1326', 'item:1326', AchievementKey::item(1326)]);

        $this->assertCount(1, $matched);
    }

    #[Test]
    public function mapping_issue_matches_only_on_the_full_mapping(): void
    {
        $mapping = $this->mapping(1, 'item:1326');

        $this->assertTrue($mapping->matches(self::PROJECT_ID, self::WORLD_A, 'item:1326'));
        $this->assertFalse($mapping->matches(9999, self::WORLD_A, 'item:1326'));
        $this->assertFalse($mapping->matches(self::PROJECT_ID, self::WORLD_B, 'item:1326'));
        $this->assertFalse($mapping->matches(self::PROJECT_ID, self::WORLD_A, 'item:9999'));
    }

    private function mapping(
        int $issueId,
        string $achievementKey,
        ?string $worldKey = null,
        ?int $projectId = null,
        bool $done = false,
    ): MappingIssue {
        return new MappingIssue(
            projectId: $projectId ?? self::PROJECT_ID,
            issueId: $issueId,
            issueKey: 'TRAINING_YOSHIZUMI-'.$issueId,
            worldKey: $worldKey ?? self::WORLD_A,
            achievementKey: $achievementKey,
            done: $done,
            statusId: $done ? 4 : 1,
        );
    }
}
