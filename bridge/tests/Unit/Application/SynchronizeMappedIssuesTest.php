<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\SynchronizeMappedIssues;
use App\Domain\Mapping\CompletionStatus;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Registry\RegistryIssue;
use App\Domain\Snapshot\WorldKey;
use App\Infrastructure\Backlog\Exceptions\BacklogTransportException;
use Illuminate\Http\Client\ConnectionException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Infrastructure\Backlog\Mapping\FakeMappingBacklog;
use Tests\Unit\Infrastructure\Backlog\Mapping\MappingTestCase;

/**
 * Task 06: 完了済み Registry set と mappingIndex の join
 * (docs/design.md §12 手順 5/8/9 / AC-06, AC-07, AC-10, AC-14, AC-15, AC-19)。
 *
 * Backlog stub のセットアップは Mapping Repository のテストと共有する
 * (`MappingTestCase`)。実 Backlog へは接続しない。
 */
final class SynchronizeMappedIssuesTest extends MappingTestCase
{
    #[Test]
    public function it_does_not_touch_mappings_whose_achievement_is_not_registered(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');

        // 事前 Mapping。Registry はまだ無い (AC-07)。
        $result = $this->synchronizer()->synchronize($this->world(), []);

        $this->assertSame([], $result->results);
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertFalse($this->backlog->isDone($issueKey));
    }

    #[Test]
    public function it_completes_the_issue_once_the_achievement_is_registered(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');
        $synchronizer = $this->synchronizer();

        $synchronizer->synchronize($this->world(), []);
        $this->assertFalse($this->backlog->isDone($issueKey));

        $result = $synchronizer->synchronize($this->world(), ['boss:king_slime']);

        $this->assertSame([$issueKey], $result->completedIssueKeys());
        $this->assertTrue($this->backlog->isDone($issueKey));
        $this->assertFalse($result->hasFailures());
    }

    #[Test]
    public function it_completes_every_issue_mapped_to_the_same_achievement(): void
    {
        $first = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $second = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $third = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        $result = $this->synchronizer()->synchronize($this->world(), ['item:1326']);

        $this->assertSame([$first, $second, $third], $result->completedIssueKeys());
        $this->assertSame([$first, $second, $third], $this->backlog->patchedIssueKeys());
    }

    #[Test]
    public function it_ignores_mapping_less_and_completed_training_issues(): void
    {
        $training = $this->backlog->addTrainingIssue();
        $completedTraining = $this->backlog->addTrainingIssue(done: true);
        $completedMapped = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326', done: true);
        $partial = $this->backlog->addIssue(summary: '攻略課題', customFields: [
            self::RECORD_TYPE_FIELD_ID => '',
            self::WORLD_KEY_FIELD_ID => self::WORLD_A,
        ]);
        $unknownType = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326', recordType: 'foo');

        // Registry は達成済み。それでも上記のどれも更新してはいけない (AC-19)。
        $result = $this->synchronizer()->synchronize($this->world(), ['item:1326', 'boss:king_slime']);

        $this->assertSame([], $result->results);
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertSame([], $this->backlog->patchedIssueKeys());

        foreach ([$training, $partial, $unknownType] as $untouched) {
            $this->assertFalse($this->backlog->isDone($untouched));
        }

        // 完了済みの課題は完了済みのまま。状態を戻すこともしない。
        $this->assertTrue($this->backlog->isDone($completedTraining));
        $this->assertTrue($this->backlog->isDone($completedMapped));
    }

    #[Test]
    public function it_scans_the_mapping_index_once_per_synchronization_not_once_per_achievement(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:eye_of_cthulhu');
        $this->backlog->addMappingIssue(self::WORLD_A, 'world:hardmode');

        $result = $this->synchronizer()->synchronize($this->world(), [
            'item:1326',
            'boss:king_slime',
            'boss:eye_of_cthulhu',
            'world:hardmode',
        ]);

        $this->assertCount(4, $result->completedIssueKeys());
        // Achievement は 4 件でも full scan は 1 回だけ (docs/design.md §12, §20)。
        $this->assertSame(1, $this->backlog->fullScanCount());
        $this->assertCount(1, $this->backlog->listRequests());
    }

    #[Test]
    public function it_reuses_a_preloaded_index_without_scanning_again(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        // docs/design.md §12: Snapshot 処理は手順 5 で index を作り、手順 8-9 で使う。
        $index = $this->repository()->loadIncompleteIndex($this->world());
        $this->assertSame(1, $this->backlog->fullScanCount());

        $this->synchronizer()->synchronize($this->world(), ['item:1326'], $index);

        $this->assertSame(1, $this->backlog->fullScanCount());
    }

    #[Test]
    public function it_accepts_the_completed_registry_set_from_the_registry_index(): void
    {
        $registered = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $notRegistered = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');

        $registry = new RegistryIndex(WorldKey::fromString(self::WORLD_A), self::PROJECT_ID, [
            $this->registryIssue('item:1326', done: true),
            // 未完了 Registry は「達成済み」ではない。
            $this->registryIssue('boss:king_slime', done: false),
        ]);

        $result = $this->synchronizer()->synchronizeWithRegistry($this->world(), $registry);

        $this->assertSame([$registered], $result->completedIssueKeys());
        $this->assertFalse($this->backlog->isDone($notRegistered));
    }

    #[Test]
    public function it_completes_a_post_hoc_mapping_on_the_next_synchronization(): void
    {
        $synchronizer = $this->synchronizer();

        // Registry は既にある (Item は chest から取り出し済み) が Mapping はまだ無い。
        $first = $synchronizer->synchronize($this->world(), ['item:1326']);
        $this->assertSame([], $first->results);

        $late = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        // 再 scan で後付け Mapping を検出して完了させる (AC-06)。
        $second = $synchronizer->synchronize($this->world(), ['item:1326']);

        $this->assertSame([$late], $second->completedIssueKeys());
        $this->assertTrue($this->backlog->isDone($late));
    }

    #[Test]
    public function it_completes_a_reopened_issue_on_the_next_synchronization(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $synchronizer = $this->synchronizer();

        $synchronizer->synchronize($this->world(), ['item:1326']);
        $this->assertTrue($this->backlog->isDone($issueKey));

        // 完了済みなので次回同期では対象外 (無駄な PATCH を打たない)。
        $idle = $synchronizer->synchronize($this->world(), ['item:1326']);
        $this->assertSame([], $idle->results);
        $this->assertCount(1, $this->backlog->patchedIssueKeys());

        // 利用者が reopen -> 次回同期で再び完了 (docs/design.md §12.1)。
        $this->backlog->setStatus($issueKey, done: false);

        $reopened = $synchronizer->synchronize($this->world(), ['item:1326']);

        $this->assertSame([$issueKey], $reopened->completedIssueKeys());
        $this->assertTrue($this->backlog->isDone($issueKey));
    }

    #[Test]
    public function it_does_not_report_a_partial_failure_as_success(): void
    {
        $failing = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $succeeding = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        $this->backlog->interceptNext('PATCH', '/api/v2/issues/'.$failing, static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->synchronizer()->synchronize($this->world(), ['item:1326']);

        $this->assertTrue($result->hasFailures());
        $this->assertSame([$succeeding], $result->completedIssueKeys());
        $this->assertSame($failing, $result->failures()[0]->issueKey);
        $this->assertFalse($this->backlog->isDone($failing));
        $this->assertTrue($this->backlog->isDone($succeeding));

        // 失敗した課題は次回同期で再試行され、完了できる (AC-10)。
        $retry = $this->synchronizer()->synchronize($this->world(), ['item:1326']);

        $this->assertSame([$failing], $retry->completedIssueKeys());
        $this->assertTrue($this->backlog->isDone($failing));
    }

    #[Test]
    public function it_does_not_swallow_a_scan_failure(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $this->backlog->interceptNext('GET', '/api/v2/issues', static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            // 「0 件だった」と読み替えて成功扱いしない。
            $this->synchronizer()->synchronize($this->world(), ['item:1326']);

            $this->fail('scan failure must not be treated as zero results.');
        } catch (BacklogTransportException $exception) {
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_rejects_an_index_built_for_another_world(): void
    {
        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_B));

        $this->expectException(InvalidArgumentException::class);

        $this->synchronizer()->synchronize($this->world(), ['item:1326'], $index);
    }

    #[Test]
    public function it_summarizes_the_synchronization_for_task_07(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $alreadyDone = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:skeletron');

        // scan 後・PATCH 前に利用者が手動完了させる。
        $this->backlog->interceptNext('GET', '/api/v2/issues/'.$alreadyDone, function (FakeMappingBacklog $backlog) use ($alreadyDone): mixed {
            $backlog->setStatus($alreadyDone, done: true);

            return null;
        });

        $result = $this->synchronizer()->synchronize($this->world(), ['item:1326', 'boss:king_slime']);

        $this->assertSame(3, $result->scannedMappings);
        $this->assertSame(2, $result->completedAchievements);
        $this->assertSame(1, $result->updatedCount());
        $this->assertCount(1, $result->withStatus(CompletionStatus::AlreadyCompleted));
        $this->assertSame(0, $result->toLogContext()['failed']);
    }

    private function synchronizer(): SynchronizeMappedIssues
    {
        return new SynchronizeMappedIssues($this->repository(), $this->logger);
    }

    private function world(): WorldKey
    {
        return WorldKey::fromString(self::WORLD_A);
    }

    private function registryIssue(string $achievementKey, bool $done): RegistryIssue
    {
        return new RegistryIssue(
            projectId: self::PROJECT_ID,
            issueId: crc32($achievementKey),
            issueKey: self::PROJECT_KEY.'-registry-'.$achievementKey,
            recordType: 'registry',
            worldKey: self::WORLD_A,
            achievementKey: $achievementKey,
            done: $done,
            statusId: $done ? self::DONE_STATUS_ID : self::OPEN_STATUS_ID,
        );
    }
}
