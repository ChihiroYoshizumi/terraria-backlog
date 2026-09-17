<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Registry;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementKey;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Registry\RegistryIssue;
use App\Domain\Registry\RegistrySubject;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Snapshot-scoped Registry Index (docs/design.md §10.3) の検証。
 */
final class RegistryIndexTest extends TestCase
{
    private const PROJECT_ID = 4242;

    private const WORLD_A = 'terraria:111111111';

    private const WORLD_B = 'terraria:222222222';

    private function issue(
        string $worldKey,
        string $achievementKey,
        bool $done,
        int $id = 1,
        int $projectId = self::PROJECT_ID,
        string $recordType = 'registry',
    ): RegistryIssue {
        return new RegistryIssue(
            projectId: $projectId,
            issueId: $id,
            issueKey: 'TRAINING_YOSHIZUMI-'.$id,
            recordType: $recordType,
            worldKey: $worldKey,
            achievementKey: $achievementKey,
            done: $done,
        );
    }

    private function index(): RegistryIndex
    {
        return new RegistryIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID);
    }

    #[Test]
    public function it_groups_registries_by_achievement_key(): void
    {
        $index = $this->index();
        $index->add($this->issue(self::WORLD_A, 'item:1326', true, 1));
        $index->add($this->issue(self::WORLD_A, 'item:1326', false, 2));
        $index->add($this->issue(self::WORLD_A, 'boss:plantera', true, 3));

        $this->assertCount(2, $index->forKey('item:1326'));
        $this->assertCount(1, $index->forKey(AchievementKey::boss('plantera')));
        $this->assertSame([], $index->forKey('world:hardmode'));
        $this->assertSame(3, $index->count());
        $this->assertSame(['boss:plantera', 'item:1326'], $index->achievementKeys());
        $this->assertSame(['boss:plantera', 'item:1326'], $index->completedAchievementKeys());
    }

    #[Test]
    public function it_only_reports_achievements_with_a_completed_registry(): void
    {
        $index = $this->index();
        $index->add($this->issue(self::WORLD_A, 'item:1326', false, 1));

        $this->assertTrue($index->has('item:1326'));
        $this->assertSame([], $index->completedAchievementKeys());
    }

    #[Test]
    public function it_refuses_registries_from_another_world(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->index()->add($this->issue(self::WORLD_B, 'item:1326', true));
    }

    #[Test]
    public function it_refuses_registries_from_another_project(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->index()->add($this->issue(self::WORLD_A, 'item:1326', true, 1, 9999));
    }

    #[Test]
    public function it_refuses_non_registry_records(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->index()->add($this->issue(self::WORLD_A, 'item:1326', true, 1, self::PROJECT_ID, ''));
    }

    #[Test]
    public function it_replaces_entries_with_the_verified_state(): void
    {
        $index = $this->index();
        $index->add($this->issue(self::WORLD_A, 'item:1326', false, 1));

        $index->replace('item:1326', [$this->issue(self::WORLD_A, 'item:1326', true, 1)]);

        $this->assertCount(1, $index->forKey('item:1326'));
        $this->assertTrue($index->forKey('item:1326')[0]->done);

        $index->replace('item:1326', []);
        $this->assertSame([], $index->forKey('item:1326'));
    }

    #[Test]
    public function registry_issue_matches_only_on_the_full_logical_primary_key(): void
    {
        $issue = $this->issue(self::WORLD_A, 'item:1326', true);

        $this->assertTrue($issue->matches(self::PROJECT_ID, self::WORLD_A, 'item:1326'));
        $this->assertFalse($issue->matches(9999, self::WORLD_A, 'item:1326'));
        $this->assertFalse($issue->matches(self::PROJECT_ID, self::WORLD_B, 'item:1326'));
        $this->assertFalse($issue->matches(self::PROJECT_ID, self::WORLD_A, 'item:13'));
    }

    #[Test]
    public function it_builds_a_human_readable_subject(): void
    {
        $this->assertSame(
            '[Terraria Registry] Rod of Discord (item 1326)',
            RegistrySubject::for(new Achievement(AchievementKey::item(1326), ['itemName' => 'Rod of Discord'])),
        );

        $this->assertSame(
            '[Terraria Registry] Item 1326',
            RegistrySubject::for(new Achievement(AchievementKey::item(1326))),
        );

        $this->assertSame(
            '[Terraria Registry] Eye Of Cthulhu',
            RegistrySubject::for(new Achievement(AchievementKey::boss('eye_of_cthulhu'))),
        );

        $this->assertSame(
            '[Terraria Registry] Hardmode',
            RegistrySubject::for(new Achievement(AchievementKey::world('hardmode'))),
        );
    }
}
