<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog\Mapping;

use App\Domain\Mapping\MappingRejection;
use App\Infrastructure\Backlog\MappingIssueMapper;
use App\Infrastructure\Backlog\ProjectConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * PHP 側 final match の純ロジック (docs/design.md §11)。
 *
 * HTTP を介さずに「Mapping と認める条件」を 1 つずつ確認する。
 */
final class MappingIssueMapperTest extends TestCase
{
    private const PROJECT_ID = 4242;

    private const RECORD_TYPE_FIELD_ID = 123456;

    private const WORLD_KEY_FIELD_ID = 123457;

    private const ACHIEVEMENT_KEY_FIELD_ID = 123458;

    private const DONE_STATUS_ID = 4;

    #[Test]
    public function it_accepts_a_fully_mapped_incomplete_issue(): void
    {
        $candidate = $this->mapper()->map($this->issue());

        $this->assertTrue($candidate->isAccepted());
        $this->assertSame('terraria:1', $candidate->issue->worldKey);
        $this->assertSame('item:1326', $candidate->issue->achievementKey);
        $this->assertFalse($candidate->issue->done);
        $this->assertSame(1, $candidate->issue->statusId);
    }

    #[Test]
    public function it_marks_the_issue_done_only_by_the_configured_done_status_id(): void
    {
        $done = $this->mapper()->map($this->issue(statusId: self::DONE_STATUS_ID));
        $other = $this->mapper()->map($this->issue(statusId: 3));

        $this->assertTrue($done->issue->done);
        // 表示名 (「処理済み」等) ではなく validated Done Status ID だけを見る。
        $this->assertFalse($other->issue->done);
    }

    #[Test]
    public function it_trims_custom_field_values(): void
    {
        $candidate = $this->mapper()->map($this->issue(worldKey: "  terraria:1\n", achievementKey: ' item:1326 '));

        $this->assertTrue($candidate->isAccepted());
        $this->assertSame('terraria:1', $candidate->issue->worldKey);
        $this->assertSame('item:1326', $candidate->issue->achievementKey);
    }

    #[Test]
    public function whitespace_only_custom_fields_count_as_unset(): void
    {
        $candidate = $this->mapper()->map($this->issue(worldKey: '   ', achievementKey: '   '));

        $this->assertSame(MappingRejection::NoMapping, $candidate->rejection);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('rejections')]
    public function it_rejects_issues_that_are_not_mappings(array $overrides, MappingRejection $expected): void
    {
        $candidate = $this->mapper()->map($this->issue(...$overrides));

        $this->assertFalse($candidate->isAccepted());
        $this->assertSame($expected, $candidate->rejection);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, MappingRejection}>
     */
    public static function rejections(): iterable
    {
        yield 'another project' => [['projectId' => 9999], MappingRejection::NotTargetProject];
        yield 'registry record' => [['recordType' => 'registry'], MappingRejection::RegistryRecord];
        yield 'unknown record type' => [['recordType' => 'foo'], MappingRejection::InvalidRecordType];
        yield 'no mapping at all' => [['worldKey' => null, 'achievementKey' => null], MappingRejection::NoMapping];
        yield 'world key only' => [['achievementKey' => null], MappingRejection::PartialMapping];
        yield 'achievement key only' => [['worldKey' => null], MappingRejection::PartialMapping];
        yield 'empty achievement key' => [['achievementKey' => ''], MappingRejection::PartialMapping];
        yield 'missing status' => [['statusId' => null], MappingRejection::Unreadable];
        yield 'missing issue key' => [['issueKey' => ''], MappingRejection::Unreadable];
    }

    #[Test]
    public function record_type_rejections_decide_whether_to_log(): void
    {
        // 同居が前提の正常系は silent、設定ミスの可能性があるものはログ。
        $this->assertFalse(MappingRejection::NoMapping->shouldLog());
        $this->assertFalse(MappingRejection::RegistryRecord->shouldLog());
        $this->assertFalse(MappingRejection::NotTargetProject->shouldLog());
        $this->assertTrue(MappingRejection::InvalidRecordType->shouldLog());
        $this->assertTrue(MappingRejection::PartialMapping->shouldLog());
        $this->assertTrue(MappingRejection::Unreadable->shouldLog());
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(
        int $projectId = self::PROJECT_ID,
        string $issueKey = 'TRAINING_YOSHIZUMI-1',
        ?int $statusId = 1,
        string $recordType = '',
        ?string $worldKey = 'terraria:1',
        ?string $achievementKey = 'item:1326',
    ): array {
        $customFields = [
            ['id' => self::RECORD_TYPE_FIELD_ID, 'fieldTypeId' => 1, 'value' => $recordType],
        ];

        if ($worldKey !== null) {
            $customFields[] = ['id' => self::WORLD_KEY_FIELD_ID, 'fieldTypeId' => 1, 'value' => $worldKey];
        }

        if ($achievementKey !== null) {
            $customFields[] = ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'fieldTypeId' => 1, 'value' => $achievementKey];
        }

        $issue = [
            'id' => 1,
            'projectId' => $projectId,
            'issueKey' => $issueKey,
            'customFields' => $customFields,
        ];

        if ($statusId !== null) {
            $issue['status'] = ['id' => $statusId, 'name' => 'status'];
        }

        return $issue;
    }

    private function mapper(): MappingIssueMapper
    {
        return new MappingIssueMapper(new ProjectConfiguration(
            projectId: self::PROJECT_ID,
            projectKey: 'TRAINING_YOSHIZUMI',
            recordTypeFieldId: self::RECORD_TYPE_FIELD_ID,
            worldKeyFieldId: self::WORLD_KEY_FIELD_ID,
            achievementKeyFieldId: self::ACHIEVEMENT_KEY_FIELD_ID,
            doneStatusId: self::DONE_STATUS_ID,
            registryIssueTypeId: 11,
            registryPriorityId: 3,
        ));
    }
}
