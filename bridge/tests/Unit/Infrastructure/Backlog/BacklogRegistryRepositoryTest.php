<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementKey;
use App\Domain\Registry\RegistryFailureReason;
use App\Domain\Registry\RegistryStatus;
use App\Domain\Snapshot\WorldKey;
use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\BacklogRegistryRepository;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\IssueListPaginator;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Task 05: Achievement Registry Repository と冪等性
 * (docs/design.md §10, §20, §21 / AC-03, AC-05, AC-11, AC-12, AC-13)。
 *
 * 実 Backlog へは接続しない。`FakeBacklog` は Issue List の絞り込みを無視して
 * 全件返すため、Project ID / Record Type / World Key / Terraria Key の一致判定は
 * すべて PHP 側の final match が担う。
 */
final class BacklogRegistryRepositoryTest extends TestCase
{
    private const BASE_URL = 'https://backlog.test';

    private const API_KEY = 'registry-secret-api-key-abcdef123456';

    private const PROJECT_KEY = 'TRAINING_YOSHIZUMI';

    private const PROJECT_ID = 4242;

    private const OTHER_PROJECT_ID = 9999;

    private const RECORD_TYPE_FIELD_ID = 123456;

    private const WORLD_KEY_FIELD_ID = 123457;

    private const ACHIEVEMENT_KEY_FIELD_ID = 123458;

    private const DONE_STATUS_ID = 4;

    private const OPEN_STATUS_ID = 1;

    private const ISSUE_TYPE_ID = 11;

    private const PRIORITY_ID = 3;

    private const WORLD_A = 'terraria:111111111';

    private const WORLD_B = 'terraria:222222222';

    private FakeBacklog $backlog;

    private RecordingLockFactory $locks;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('backlog.base_url', self::BASE_URL);
        config()->set('backlog.api_key', self::API_KEY);
        config()->set('backlog.project_key', self::PROJECT_KEY);
        config()->set('backlog.custom_fields', [
            'record_type' => ['id' => self::RECORD_TYPE_FIELD_ID, 'name' => 'Terraria Record Type'],
            'world_key' => ['id' => self::WORLD_KEY_FIELD_ID, 'name' => 'Terraria World Key'],
            'achievement_key' => ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'name' => 'Terraria Key'],
        ]);
        config()->set('backlog.done_status_id', self::DONE_STATUS_ID);
        config()->set('backlog.registry_issue_type_id', self::ISSUE_TYPE_ID);
        config()->set('backlog.registry_priority_id', self::PRIORITY_ID);

        // validated ProjectConfiguration を一度だけ解決しておく (memoize される)。
        // 以降のテストは Registry 操作のリクエストだけを観測する。
        $this->fakeProjectConfigurationEndpoints();
        $this->app->make(ProjectConfigurationRepository::class)->resolve();

        $this->backlog = new FakeBacklog(
            baseUrl: self::BASE_URL,
            projectId: self::PROJECT_ID,
            projectKey: self::PROJECT_KEY,
            fieldIds: [
                'record_type' => self::RECORD_TYPE_FIELD_ID,
                'world_key' => self::WORLD_KEY_FIELD_ID,
                'achievement_key' => self::ACHIEVEMENT_KEY_FIELD_ID,
            ],
            doneStatusId: self::DONE_STATUS_ID,
            openStatusId: self::OPEN_STATUS_ID,
        );
        $this->backlog->install();

        $this->locks = new RecordingLockFactory($this->backlog);
        $this->logger = new RecordingLogger;
    }

    private function fakeProjectConfigurationEndpoints(): void
    {
        Http::fake([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response(['id' => 1], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_KEY.'*' => Http::response([
                'id' => self::PROJECT_ID,
                'projectKey' => self::PROJECT_KEY,
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/statuses*' => Http::response([
                ['id' => self::DONE_STATUS_ID, 'projectId' => self::PROJECT_ID, 'name' => '完了'],
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/issueTypes*' => Http::response([
                ['id' => self::ISSUE_TYPE_ID, 'projectId' => self::PROJECT_ID, 'name' => 'タスク'],
            ], 200),
            self::BASE_URL.'/api/v2/priorities*' => Http::response([
                ['id' => self::PRIORITY_ID, 'name' => '中'],
            ], 200),
        ]);
    }

    private function repository(): BacklogRegistryRepository
    {
        return new BacklogRegistryRepository(
            projects: $this->app->make(ProjectConfigurationRepository::class),
            paginator: new IssueListPaginator($this->app->make(BacklogClient::class), 100, 200),
            client: $this->app->make(BacklogClient::class),
            locks: $this->locks,
            logger: $this->logger,
        );
    }

    private function world(string $value = self::WORLD_A): WorldKey
    {
        return WorldKey::fromString($value);
    }

    private function achievement(string $key = 'item:1326', string $name = 'Rod of Discord'): Achievement
    {
        return new Achievement(AchievementKey::fromString($key), ['itemName' => $name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastCreatePayload(): array
    {
        $posts = $this->backlog->requestsMatching('POST', '/api/v2/issues');
        $this->assertNotSame([], $posts, 'POST /api/v2/issues が送信されていない。');

        return $posts[count($posts) - 1]['form'];
    }

    // ---------------------------------------------------------------- 1

    #[Test]
    public function it_creates_completes_and_verifies_when_no_registry_exists(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->assertSame(0, $index->count());

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertTrue($result->isPersisted());
        $this->assertTrue($result->wasWritten());
        $this->assertNotNull($result->issue);
        $this->assertTrue($result->issue->done);

        // create -> done 更新 -> 保存確認 (GET) がこの順で起きている。
        $this->assertSame(1, $this->backlog->countRequests('POST', '/api/v2/issues'));
        $this->assertSame(1, $this->backlog->countRequests('PATCH', '/api/v2/issues/'));
        $this->assertSame(1, $this->backlog->countRequests('GET', '/api/v2/issues/'));

        $methods = array_map(
            static fn (array $request): string => $request['method'],
            array_values(array_filter(
                $this->backlog->requests,
                static fn (array $request): bool => $request['path'] !== '/api/v2/issues',
            )),
        );
        $this->assertSame(['PATCH', 'GET'], $methods);

        // index が保存確認済みの状態へ更新されている。
        $stored = $index->forKey('item:1326');
        $this->assertCount(1, $stored);
        $this->assertTrue($stored[0]->done);
        $this->assertSame([self::WORLD_A.'|item:1326'], $this->locks->released);
    }

    #[Test]
    public function it_builds_the_create_payload_from_validated_project_configuration(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $payload = $this->lastCreatePayload();

        $this->assertSame((string) self::PROJECT_ID, $payload['projectId']);
        $this->assertSame((string) self::ISSUE_TYPE_ID, $payload['issueTypeId']);
        $this->assertSame((string) self::PRIORITY_ID, $payload['priorityId']);
        $this->assertSame('registry', $payload['customField_'.self::RECORD_TYPE_FIELD_ID]);
        $this->assertSame(self::WORLD_A, $payload['customField_'.self::WORLD_KEY_FIELD_ID]);
        $this->assertSame('item:1326', $payload['customField_'.self::ACHIEVEMENT_KEY_FIELD_ID]);
        $this->assertSame('[Terraria Registry] Rod of Discord (item 1326)', $payload['summary']);

        // 完了更新も validated Done Status ID を使う。
        $patch = $this->backlog->requestsMatching('PATCH', '/api/v2/issues/')[0];
        $this->assertSame((string) self::DONE_STATUS_ID, $patch['form']['statusId']);
    }

    // ---------------------------------------------------------------- 2

    #[Test]
    public function it_does_not_write_when_a_completed_registry_already_exists(): void
    {
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());
        $requestsAfterScan = count($this->backlog->requests);

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::AlreadyRegistered, $result->status);
        $this->assertTrue($result->isPersisted());
        $this->assertFalse($result->wasWritten());

        // AC-11: 追加の API 呼び出しも書き込みも一切しない。
        $this->assertSame($requestsAfterScan, count($this->backlog->requests));
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertSame([], $this->locks->acquired);
    }

    // ---------------------------------------------------------------- 3

    #[Test]
    public function it_repairs_an_incomplete_registry_without_creating_a_new_one(): void
    {
        $existing = $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertSame($existing['issueKey'], $result->issue?->issueKey);
        $this->assertSame(0, $this->backlog->countRequests('POST', '/api/v2/issues'));
        $this->assertSame(1, $this->backlog->countRequests('PATCH', '/api/v2/issues/'));
        $this->assertCount(1, $this->locks->acquired);
    }

    #[Test]
    public function it_re_checks_the_latest_registry_inside_the_lock_before_creating(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // 別 Worker が index 構築後・lock 取得後に作成した状況を模す。
        $this->locks->acquired = [];
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        // lock 内の最新確認で既存を発見したため、作成しない。
        $this->assertSame(RegistryStatus::AlreadyRegistered, $result->status);
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertCount(1, $this->locks->acquired);
    }

    // ---------------------------------------------------------------- 4

    #[Test]
    public function it_researches_instead_of_recreating_when_the_create_response_is_lost(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // POST は Backlog 側で成功するが、応答が timeout で失われる。
        $this->backlog->interceptNext('POST', '/api/v2/issues', function (FakeBacklog $backlog): never {
            $backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Registered, $result->status);

        // 再作成していない。
        $this->assertSame(1, $this->backlog->countRequests('POST', '/api/v2/issues'));

        // POST の後に「限定再検索」が行われている。
        $researches = $this->listRequestsAfterLast('POST', '/api/v2/issues');
        $this->assertNotSame([], $researches, 'create 応答不明後に再検索が行われていない。');
        $this->assertSame('item:1326', $researches[0]['query']['customField_'.self::ACHIEVEMENT_KEY_FIELD_ID] ?? null);
        $this->assertSame(self::WORLD_A, $researches[0]['query']['customField_'.self::WORLD_KEY_FIELD_ID] ?? null);
    }

    #[Test]
    public function it_fails_closed_when_the_create_result_stays_unknown(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // POST が届かずに失われ、Backlog 側にも何も残っていない。
        $this->backlog->interceptNext('POST', '/api/v2/issues', function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertFalse($result->isPersisted());
        $this->assertSame(RegistryFailureReason::WriteResultUnknown, $result->reason);

        // 再作成しない。再検索は行っている。
        $this->assertSame(1, $this->backlog->countRequests('POST', '/api/v2/issues'));
        $this->assertGreaterThan(1, count($this->backlog->listRequests()));
    }

    // ---------------------------------------------------------------- 5

    #[Test]
    public function it_verifies_by_researching_when_the_update_response_is_lost(): void
    {
        $existing = $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // PATCH は Backlog 側で適用されるが、応答が失われる。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', function (FakeBacklog $backlog) use ($existing): never {
            $backlog->markDone($existing['issueKey']);

            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertSame($existing['issueKey'], $result->issue?->issueKey);
        $this->assertSame(0, $this->backlog->countRequests('POST', '/api/v2/issues'));

        $researches = $this->listRequestsAfterLast('PATCH', '/api/v2/issues/');
        $this->assertNotSame([], $researches, 'update 応答不明後に再検索が行われていない。');
        $this->assertSame('item:1326', $researches[0]['query']['customField_'.self::ACHIEVEMENT_KEY_FIELD_ID] ?? null);
    }

    #[Test]
    public function it_continues_from_the_researched_state_when_a_lost_update_had_not_been_applied(): void
    {
        $existing = $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // PATCH は届かず、状態も変わっていない。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        // 再検索で「未完了のまま存在する」ことを確認し、その状態から完了させる。
        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertSame($existing['issueKey'], $result->issue?->issueKey);
        $this->assertSame(0, $this->backlog->countRequests('POST', '/api/v2/issues'));
        $this->assertSame(2, $this->backlog->countRequests('PATCH', '/api/v2/issues/'));
    }

    #[Test]
    public function it_does_not_report_success_when_the_saved_state_cannot_be_verified(): void
    {
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        // PATCH は 200 を返すが実際には反映されていない (保存確認で未完了のまま)。
        $this->backlog->interceptNext('PATCH', '/api/v2/issues/', fn (): PromiseInterface => Http::response([
            'id' => 1,
            'projectId' => self::PROJECT_ID,
            'issueKey' => 'ignored',
        ], 200));

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertSame(RegistryFailureReason::VerificationFailed, $result->reason);
        $this->assertFalse($result->isPersisted());
    }

    // ---------------------------------------------------------------- 6

    #[Test]
    public function it_fails_closed_without_writing_when_duplicates_are_all_incomplete(): void
    {
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());
        $requestsAfterScan = count($this->backlog->requests);

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertSame(RegistryFailureReason::DuplicateIncomplete, $result->reason);
        $this->assertFalse($result->isPersisted());

        // 書き込みが1回も発生していない (新規作成も既存の自動完了もしない)。
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertSame($requestsAfterScan, count($this->backlog->requests));
        $this->assertSame([], $this->locks->acquired);

        $this->assertStringContainsString('registry.duplicate_incomplete', $this->logger->dump());
    }

    // ---------------------------------------------------------------- 7

    #[Test]
    public function it_treats_duplicates_with_a_completed_one_as_a_single_logical_achievement(): void
    {
        $done = $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: false);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());
        $issueCountBefore = count($this->backlog->issues);

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::AlreadyRegistered, $result->status);
        $this->assertTrue($result->isPersisted());
        $this->assertTrue($result->hasPhysicalDuplicates());
        $this->assertSame($done['issueKey'], $result->issue?->issueKey);

        // cleanup (削除・マージ・自動完了) をしない。書き込み経路にも入らない。
        $this->assertSame(0, $this->backlog->writeCount());
        $this->assertSame($issueCountBefore, count($this->backlog->issues));
        $this->assertSame([], $this->locks->acquired);
        $this->assertStringContainsString('registry.duplicate_detected', $this->logger->dump());
    }

    // ---------------------------------------------------------------- 8

    #[Test]
    public function it_requires_an_exact_project_id_match(): void
    {
        // Backlog が別 Project の Issue を返しても Registry として採用しない。
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true, projectId: self::OTHER_PROJECT_ID);

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->assertSame(0, $index->count());

        // Issue List query は対象 Project ID へ限定して送っている。
        $listQuery = $this->backlog->listRequests()[0]['query'];
        $this->assertSame([(string) self::PROJECT_ID], $listQuery['projectId']);

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertSame(self::PROJECT_ID, $result->issue?->projectId);
    }

    #[Test]
    public function it_ignores_issues_that_are_not_registry_records(): void
    {
        // Record Type 空欄 (攻略課題) と未知の非空値は Registry として扱わない。
        $this->backlog->addPlainIssue(self::WORLD_A, 'item:1326');
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true, recordType: 'foo');

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->assertSame(0, $index->count());
    }

    // ---------------------------------------------------------------- 9

    #[Test]
    public function it_does_not_mix_world_a_registries_into_world_b(): void
    {
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);

        $repository = $this->repository();

        $indexA = $repository->loadIndex($this->world(self::WORLD_A));
        $indexB = $repository->loadIndex($this->world(self::WORLD_B));

        $this->assertSame(1, $indexA->count());
        $this->assertSame(0, $indexB->count());
        $this->assertSame(['item:1326'], $indexA->completedAchievementKeys());
        $this->assertSame([], $indexB->completedAchievementKeys());

        // World B は未達成なので、A の記録では already_registered にならない。
        $result = $repository->ensureRegistered($this->world(self::WORLD_B), $this->achievement(), $indexB);

        $this->assertSame(RegistryStatus::Registered, $result->status);
        $this->assertSame(self::WORLD_B, $result->issue?->worldKey);
        $this->assertSame(self::WORLD_B, $this->lastCreatePayload()['customField_'.self::WORLD_KEY_FIELD_ID]);

        // World A の Registry は書き換えられていない。
        $this->assertSame(1, $this->backlog->countRequests('POST', '/api/v2/issues'));
    }

    #[Test]
    public function it_rejects_an_index_built_for_another_world(): void
    {
        $repository = $this->repository();
        $indexA = $repository->loadIndex($this->world(self::WORLD_A));

        $this->expectException(\InvalidArgumentException::class);

        $repository->ensureRegistered($this->world(self::WORLD_B), $this->achievement(), $indexA);
    }

    // ---------------------------------------------------------------- 11

    #[Test]
    public function it_scans_the_registry_once_per_snapshot_not_once_per_achievement(): void
    {
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);
        $this->backlog->addRegistry(self::WORLD_A, 'boss:plantera', done: true);
        $this->backlog->addRegistry(self::WORLD_A, 'world:hardmode', done: true);
        $this->backlog->addPlainIssue();

        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        foreach (['item:1326', 'boss:plantera', 'world:hardmode'] as $key) {
            $result = $repository->ensureRegistered($this->world(), $this->achievement($key), $index);
            $this->assertSame(RegistryStatus::AlreadyRegistered, $result->status);
        }

        // Snapshot 全体で全件 scan は 1 回だけ。
        $this->assertSame(1, count($this->backlog->listRequests()));
        $this->assertSame(1, $this->backlog->fullScanCount());
    }

    #[Test]
    public function it_never_repeats_a_full_scan_even_when_several_achievements_need_writes(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        foreach (['item:1326', 'boss:plantera', 'world:hardmode'] as $key) {
            $result = $repository->ensureRegistered($this->world(), $this->achievement($key), $index);
            $this->assertSame(RegistryStatus::Registered, $result->status, $key);
        }

        // 書き込み前の最新確認は Achievement Key を限定した再検索であり、
        // 全件 scan (絞り込み無し) は最初の loadIndex の 1 回だけ。
        $this->assertSame(1, $this->backlog->fullScanCount());
        $this->assertSame(4, count($this->backlog->listRequests()));
        $this->assertSame(3, count($index->completedAchievementKeys()));
    }

    // ------------------------------------------------- scan failure / lock

    #[Test]
    public function it_does_not_treat_a_scan_failure_as_zero_results(): void
    {
        $this->backlog->interceptNext('GET', '/api/v2/issues', function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(BacklogApiException::class);

        $this->repository()->loadIndex($this->world());
    }

    #[Test]
    public function it_does_not_create_when_the_in_lock_lookup_fails(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->backlog->interceptNext('GET', '/api/v2/issues', fn (): PromiseInterface => Http::response([], 500));

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertSame(RegistryFailureReason::LookupFailed, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_takes_the_lock_around_the_whole_write_sequence(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());
        $requestsAfterScan = count($this->backlog->requests);

        $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(
            [['worldKey' => self::WORLD_A, 'achievementKey' => 'item:1326']],
            $this->locks->acquired,
        );
        // lock 取得時点では、まだ最新確認も create も送っていない。
        $this->assertSame([$requestsAfterScan], $this->locks->requestsWhenAcquired);
        // lock は保存確認まで終わってから解放される。
        $this->assertSame([self::WORLD_A.'|item:1326'], $this->locks->released);
    }

    #[Test]
    public function it_does_not_write_when_the_lock_cannot_be_acquired(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->locks->unavailable = true;

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertSame(RegistryFailureReason::LockUnavailable, $result->reason);
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_keeps_the_registry_when_the_item_is_removed_and_the_snapshot_is_replayed(): void
    {
        // AC-05: 登録済みアイテムを取り出して再同期しても未達成に戻らない。
        $this->backlog->addRegistry(self::WORLD_A, 'item:1326', done: true);

        $repository = $this->repository();

        $index = $repository->loadIndex($this->world());
        $this->assertSame(['item:1326'], $index->completedAchievementKeys());

        // 再同期 (Item がチェストに無くても Registry は消さない)。
        $replayed = $repository->loadIndex($this->world());
        $this->assertSame(['item:1326'], $replayed->completedAchievementKeys());
        $this->assertSame(0, $this->backlog->writeCount());
    }

    #[Test]
    public function it_does_not_leak_the_api_key_into_logs(): void
    {
        $repository = $this->repository();
        $index = $repository->loadIndex($this->world());

        $this->backlog->interceptNext('POST', '/api/v2/issues', function (): never {
            throw new ConnectionException('cURL error 28 while sending Backlog-API-Key: '.self::API_KEY);
        });

        $result = $repository->ensureRegistered($this->world(), $this->achievement(), $index);

        $this->assertSame(RegistryStatus::Failed, $result->status);
        $this->assertStringNotContainsString(self::API_KEY, $this->logger->dump());
        $this->assertStringNotContainsString(self::API_KEY, (string) $result->detail);
    }

    /**
     * 指定リクエストの「最後の1件より後」に送られた Issue List リクエスト。
     *
     * @return list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>}>
     */
    private function listRequestsAfterLast(string $method, string $needle): array
    {
        $lastIndex = null;

        foreach ($this->backlog->requests as $index => $request) {
            if ($request['method'] === $method && str_contains($request['path'], $needle)) {
                $lastIndex = $index;
            }
        }

        $this->assertNotNull($lastIndex, sprintf('%s %s が送信されていない。', $method, $needle));

        return array_values(array_filter(
            array_slice($this->backlog->requests, $lastIndex + 1),
            static fn (array $request): bool => $request['method'] === 'GET' && $request['path'] === '/api/v2/issues',
        ));
    }
}
