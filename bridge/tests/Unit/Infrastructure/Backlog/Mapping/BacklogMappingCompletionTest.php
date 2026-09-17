<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog\Mapping;

use App\Domain\Mapping\CompletionFailureReason;
use App\Domain\Mapping\CompletionStatus;
use App\Domain\Mapping\MappingIssue;
use App\Domain\Snapshot\WorldKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Task 06: 攻略課題の完了更新 (docs/design.md §12, §12.1, §21 / AC-10, AC-11, AC-15)。
 *
 * 不変条件は「PATCH の前に必ず current issue を再取得し、Project ID / 未完了 /
 * Record Type / World Key / Terraria Key をすべて再確認する」こと。
 * 条件が 1 つでも崩れていたら **PATCH を 1 回も発行しない**。
 */
final class BacklogMappingCompletionTest extends MappingTestCase
{
    #[Test]
    public function it_refetches_the_issue_before_patching_and_completes_it(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Completed, $result->status);
        $this->assertTrue($result->wasUpdated());
        $this->assertTrue($this->backlog->isDone($issueKey));
        $this->assertSame([$issueKey], $this->backlog->patchedIssueKeys());

        // GET (再取得) -> PATCH の順であること。
        $sequence = array_values(array_map(
            static fn (array $request): string => $request['method'].' '.$request['path'],
            array_filter(
                $this->backlog->requests,
                static fn (array $request): bool => $request['path'] !== '/api/v2/issues',
            ),
        ));
        $this->assertSame([
            'GET /api/v2/issues/'.$issueKey,
            'PATCH /api/v2/issues/'.$issueKey,
        ], $sequence);

        // 状態 ID は validated config 由来。表示名や固定値を使わない。
        $patch = $this->backlog->requestsMatching('PATCH', '/api/v2/issues/')[0];
        $this->assertSame((string) self::DONE_STATUS_ID, (string) $patch['form']['statusId']);
    }

    #[Test]
    public function it_does_not_patch_an_issue_that_was_completed_after_the_scan(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        // scan 後・PATCH 前に利用者が手動で完了させた。
        $this->backlog->setStatus($issueKey, done: true);

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::AlreadyCompleted, $result->status);
        $this->assertFalse($result->wasUpdated());
        $this->assertTrue($result->isDone());
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_patch_when_the_mapping_was_removed(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        // 利用者が Mapping 属性を削除した (AC-15)。
        $this->backlog->setCustomField($issueKey, self::ACHIEVEMENT_KEY_FIELD_ID, null);

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Skipped, $result->status);
        $this->assertSame(CompletionFailureReason::MappingChanged, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertFalse($this->backlog->isDone($issueKey));
    }

    #[Test]
    public function it_does_not_patch_when_the_mapping_points_to_another_achievement(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->setCustomField($issueKey, self::ACHIEVEMENT_KEY_FIELD_ID, 'item:9999');

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Skipped, $result->status);
        $this->assertSame(CompletionFailureReason::MappingMismatch, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertFalse($this->backlog->isDone($issueKey));
    }

    #[Test]
    public function it_does_not_patch_when_the_world_key_changed(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->setCustomField($issueKey, self::WORLD_KEY_FIELD_ID, self::WORLD_B);

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Skipped, $result->status);
        $this->assertSame(CompletionFailureReason::MappingMismatch, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_patch_when_the_issue_belongs_to_another_project(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->setProjectId($issueKey, self::OTHER_PROJECT_ID);

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Skipped, $result->status);
        $this->assertSame(CompletionFailureReason::MappingChanged, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_patch_when_an_unknown_record_type_appeared(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->setCustomField($issueKey, self::RECORD_TYPE_FIELD_ID, 'foo');

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Skipped, $result->status);
        $this->assertSame(CompletionFailureReason::MappingChanged, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_patch_when_the_refetch_fails(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->interceptNext('GET', '/api/v2/issues/', static function (): mixed {
            return Http::response(['errors' => [['message' => 'internal']]], 500);
        });

        $result = $this->repository()->complete($mapping);

        // 状態を確認できないまま推測で更新しない。
        $this->assertSame(CompletionStatus::Failed, $result->status);
        $this->assertSame(CompletionFailureReason::LookupFailed, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_report_success_when_the_patch_is_rejected(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', static function (): mixed {
            return Http::response(['errors' => [['message' => 'invalid status']]], 400);
        });

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Failed, $result->status);
        $this->assertSame(CompletionFailureReason::WriteFailed, $result->reason);
        $this->assertFalse($result->isDone());
        $this->assertFalse($this->backlog->isDone($issueKey));
        $this->assertCount(1, $this->logger->withMessage('issue.completion_failed'));
    }

    #[Test]
    public function it_fails_closed_when_the_patch_result_stays_unknown(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        // timeout。Backlog 側には適用されていない。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Failed, $result->status);
        $this->assertSame(CompletionFailureReason::WriteResultUnknown, $result->reason);
        $this->assertFalse($this->backlog->isDone($issueKey));
        // 再取得で現在状態を確認している。
        $this->assertSame(2, $this->backlog->countRequests('GET', '/api/v2/issues/'.$issueKey));
    }

    #[Test]
    public function it_recovers_when_a_lost_patch_had_actually_been_applied(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        // 適用はされたが応答が失われた。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', static function (FakeMappingBacklog $backlog) use ($issueKey): never {
            $backlog->setStatus($issueKey, done: true);

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Completed, $result->status);
        $this->assertTrue($this->backlog->isDone($issueKey));
        // 二重 PATCH はしない。
        $this->assertSame([$issueKey], $this->backlog->patchedIssueKeys());
    }

    #[Test]
    public function it_does_not_report_success_when_the_update_is_not_reflected(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        // 200 は返るが状態は変わっていない。応答だけを根拠に success としない。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', static function (FakeMappingBacklog $backlog): mixed {
            return Http::response($backlog->issues[0], 200);
        });

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Failed, $result->status);
        $this->assertSame(CompletionFailureReason::VerificationFailed, $result->reason);
        $this->assertFalse($this->backlog->isDone($issueKey));
    }

    #[Test]
    public function it_completes_a_reopened_issue_again(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $repository = $this->repository();

        $first = $repository->complete($this->loadMapping('item:1326'));
        $this->assertSame(CompletionStatus::Completed, $first->status);

        // 利用者が reopen した。次の scan で未完了 Mapping として拾い直す。
        $this->backlog->setStatus($issueKey, done: false);

        $second = $repository->complete($this->loadMapping('item:1326'));

        $this->assertSame(CompletionStatus::Completed, $second->status);
        $this->assertTrue($this->backlog->isDone($issueKey));
        $this->assertSame([$issueKey, $issueKey], $this->backlog->patchedIssueKeys());
    }

    #[Test]
    public function it_does_not_leak_the_api_key_into_failure_details(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $mapping = $this->loadMapping('item:1326');

        $this->backlog->interceptNext('GET', '/api/v2/issues/', static function (): never {
            throw new ConnectionException('cURL error 7: failed to connect');
        });

        $result = $this->repository()->complete($mapping);

        $this->assertSame(CompletionStatus::Failed, $result->status);
        $this->assertStringNotContainsString(self::API_KEY, (string) $result->detail);
        $this->assertStringNotContainsString(self::API_KEY, json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }

    /**
     * scan 経由で MappingIssue を 1 件取り出す。テストでも read model を手組みしない。
     */
    private function loadMapping(string $achievementKey): MappingIssue
    {
        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));
        $issues = $index->forKey($achievementKey);

        $this->assertNotSame([], $issues, 'テスト前提: 未完了 Mapping が index に存在すること.');

        return $issues[0];
    }
}
