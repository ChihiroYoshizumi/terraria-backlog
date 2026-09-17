<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog\Mapping;

use App\Domain\Mapping\MappingIssue;
use App\Domain\Snapshot\WorldKey;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\Exceptions\BacklogServerException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * Task 06: Mapping scan (docs/design.md §11, §20 / AC-14, AC-15, AC-19)。
 *
 * 対象 Project には無関係な研修課題が同居する。scan は **読み取りのみ** で
 * あり、どの Issue の状態も変えないことを毎回確認する。
 */
final class BacklogMappingScanTest extends MappingTestCase
{
    #[Test]
    public function it_indexes_incomplete_mapping_issues_for_the_target_world(): void
    {
        $boss = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:eye_of_cthulhu');
        $item = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(['boss:eye_of_cthulhu', 'item:1326'], $index->achievementKeys());
        $this->assertSame(2, $index->count());
        $this->assertSame($boss, $index->forKey('boss:eye_of_cthulhu')[0]->issueKey);
        $this->assertSame($item, $index->forKey('item:1326')[0]->issueKey);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_ignores_mapping_less_training_issues_without_touching_them(): void
    {
        $this->backlog->addTrainingIssue();
        $this->backlog->addTrainingIssue(done: true);
        // Custom Field は存在するが空文字、という研修課題もある。
        $this->backlog->addMappingIssue(worldKey: '', achievementKey: '', summary: '研修課題');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(0, $index->count());
        // 属性未設定をエラーにしない (docs/specs/backlog-mapping.md)。課題ごとのログも出さない。
        $this->assertSame([], $this->logger->withMessage('mapping.no_mapping'));
        // 最重要: 一般課題へ一切書き込まない。
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertSame([], $this->backlog->patchedIssueKeys());
    }

    #[Test]
    public function it_ignores_partial_mappings_and_logs_a_diagnostic(): void
    {
        $worldOnly = $this->backlog->addIssue(summary: '攻略課題', customFields: [
            self::RECORD_TYPE_FIELD_ID => '',
            self::WORLD_KEY_FIELD_ID => self::WORLD_A,
        ]);
        $keyOnly = $this->backlog->addIssue(summary: '攻略課題', customFields: [
            self::RECORD_TYPE_FIELD_ID => '',
            self::ACHIEVEMENT_KEY_FIELD_ID => 'boss:king_slime',
        ]);

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(0, $index->count());
        $this->assertSame(0, $this->backlog->writeCount());

        $logs = $this->logger->withMessage('mapping.partial_mapping');
        $this->assertCount(2, $logs);
        $this->assertSame(
            [$worldOnly, $keyOnly],
            array_map(static fn (array $log): string => (string) $log['context']['mapping_issue_key'], $logs),
        );
    }

    #[Test]
    public function it_ignores_unknown_record_types_and_logs_a_diagnostic(): void
    {
        $unknown = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime', recordType: 'foo');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(0, $index->count());
        $this->assertSame(0, $this->backlog->writeCount());

        $logs = $this->logger->withMessage('mapping.invalid_record_type');
        $this->assertCount(1, $logs);
        $this->assertSame($unknown, $logs[0]['context']['mapping_issue_key']);
        $this->assertSame('foo', $logs[0]['context']['recordType']);
    }

    #[Test]
    public function it_excludes_completed_general_and_mapped_issues(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime', done: true);
        $this->backlog->addTrainingIssue(done: true);
        $open = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:eye_of_cthulhu');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        // 完了済みは scan 対象外。1 件も index に入らない (AC-19)。
        $this->assertSame(['boss:eye_of_cthulhu'], $index->achievementKeys());
        $this->assertSame($open, $index->forKey('boss:eye_of_cthulhu')[0]->issueKey);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_excludes_registry_records_from_the_mapping_index(): void
    {
        $this->backlog->addRegistryIssue(self::WORLD_A, 'item:1326');
        // 未完了の Registry も Mapping ではない。
        $this->backlog->addRegistryIssue(self::WORLD_A, 'boss:king_slime', done: false);

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(0, $index->count());
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_requires_an_exact_project_id_match(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime', projectId: self::OTHER_PROJECT_ID);

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        // Backlog の検索条件ではなく PHP 側の完全一致だけを根拠にする。
        $this->assertSame(0, $index->count());
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_mix_world_a_mappings_into_world_b(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_B));

        $this->assertSame(0, $index->count());
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_keeps_every_issue_mapped_to_the_same_achievement(): void
    {
        $first = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');
        $second = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(
            [$first, $second],
            array_map(static fn (MappingIssue $issue): string => $issue->issueKey, $index->forKey('item:1326')),
        );
    }

    #[Test]
    public function it_ignores_issues_whose_status_cannot_be_read(): void
    {
        // status を返さない応答を「未完了」と推測しない。
        $unreadable = $this->backlog->addIssue(
            summary: '攻略課題',
            customFields: [
                self::RECORD_TYPE_FIELD_ID => '',
                self::WORLD_KEY_FIELD_ID => self::WORLD_A,
                self::ACHIEVEMENT_KEY_FIELD_ID => 'boss:king_slime',
            ],
            withStatus: false,
        );

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(0, $index->count());
        $logs = $this->logger->withMessage('mapping.unreadable_issue');
        $this->assertCount(1, $logs);
        $this->assertSame($unreadable, $logs[0]['context']['mapping_issue_key']);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_treat_a_scan_failure_as_zero_results(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');
        $this->backlog->interceptNext('GET', '/api/v2/issues', static function (): mixed {
            return Http::response(['errors' => [['message' => 'internal']]], 500);
        });

        $this->expectException(BacklogServerException::class);

        $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));
    }

    #[Test]
    public function it_reads_every_page_of_the_issue_list(): void
    {
        // 1 ページ (100 件) を超える Issue があっても取りこぼさない。
        for ($i = 0; $i < 100; $i++) {
            $this->backlog->addTrainingIssue();
        }

        $last = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:skeletron');

        $index = $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $this->assertSame(1, $index->count());
        $this->assertSame($last, $index->forKey('boss:skeletron')[0]->issueKey);
        $this->assertCount(2, $this->backlog->listRequests());
    }

    #[Test]
    public function it_detects_a_post_hoc_mapping_on_a_later_scan(): void
    {
        $repository = $this->repository();
        $world = WorldKey::fromString(self::WORLD_A);

        // Registry 登録時点では Mapping 課題がまだ無い (AC-06)。
        $this->assertSame(0, $repository->loadIncompleteIndex($world)->count());

        // 後から攻略担当者が Mapping を追加する。Item は chest から取り出し済みでよい。
        $late = $this->backlog->addMappingIssue(self::WORLD_A, 'item:1326');

        $reloaded = $repository->loadIncompleteIndex($world);

        $this->assertSame(1, $reloaded->count());
        $this->assertSame($late, $reloaded->forKey('item:1326')[0]->issueKey);
    }

    #[Test]
    public function it_detects_a_reopened_issue_on_a_later_scan(): void
    {
        $issueKey = $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime', done: true);
        $repository = $this->repository();
        $world = WorldKey::fromString(self::WORLD_A);

        $this->assertSame(0, $repository->loadIncompleteIndex($world)->count());

        // 利用者が reopen した。
        $this->backlog->setStatus($issueKey, done: false);

        $reloaded = $repository->loadIncompleteIndex($world);

        $this->assertSame(1, $reloaded->count());
        $this->assertSame($issueKey, $reloaded->forKey('boss:king_slime')[0]->issueKey);
    }

    #[Test]
    public function it_reports_what_it_ignored_in_the_index_loaded_log(): void
    {
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:king_slime');
        $this->backlog->addMappingIssue(self::WORLD_A, 'boss:skeletron', done: true);
        $this->backlog->addMappingIssue(self::WORLD_B, 'boss:king_slime');
        $this->backlog->addTrainingIssue();
        $this->backlog->addRegistryIssue(self::WORLD_A, 'item:1326');

        $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));

        $logs = $this->logger->withMessage('mapping.index_loaded');
        $this->assertCount(1, $logs);

        $context = $logs[0]['context'];
        $this->assertSame(5, $context['scannedIssues']);
        $this->assertSame(1, $context['mappingIssues']);
        $this->assertSame(1, $context['ignored']['completed']);
        $this->assertSame(1, $context['ignored']['other_world']);
        $this->assertSame(1, $context['ignored']['mapping.no_mapping']);
        $this->assertSame(1, $context['ignored']['mapping.registry_record']);
    }

    #[Test]
    public function it_does_not_leak_the_api_key_when_a_scan_fails(): void
    {
        $this->backlog->interceptNext('GET', '/api/v2/issues', static function (): mixed {
            return Http::response(['errors' => [['message' => 'internal']]], 500);
        });

        try {
            $this->repository()->loadIncompleteIndex(WorldKey::fromString(self::WORLD_A));
            $this->fail('scan failure must not be swallowed.');
        } catch (BacklogApiException $exception) {
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }

        $this->assertStringNotContainsString(self::API_KEY, json_encode($this->logger->records, JSON_THROW_ON_ERROR));
    }
}
