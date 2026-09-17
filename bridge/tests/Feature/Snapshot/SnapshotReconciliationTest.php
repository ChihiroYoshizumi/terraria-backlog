<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use App\Application\BuildNotifications;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Infrastructure\Backlog\FakeBacklog;

/**
 * Task 07 の end-to-end 確認。HTTP endpoint から Backlog API 呼び出しまで、
 * 本物の `ProcessWorldSnapshot` / `BacklogRegistryRepository` /
 * `BacklogMappingRepository` を通す (docs/design.md §12, §14, §15 /
 * AC-05, AC-06, AC-09, AC-10, AC-11, AC-18)。
 *
 * 実 Backlog へは接続しない。`FakeBacklog` (Http::fake) が Issue List の
 * 絞り込みを無視して全件返すため、Project ID / Record Type / World Key /
 * Terraria Key の一致判定はすべて PHP 側の final match が担う。
 */
final class SnapshotReconciliationTest extends SnapshotTestCase
{
    private const string BASE_URL = 'https://backlog.test';

    private const string PROJECT_KEY = 'TRAINING_YOSHIZUMI';

    private const int PROJECT_ID = 4242;

    private const int RECORD_TYPE_FIELD_ID = 123456;

    private const int WORLD_KEY_FIELD_ID = 123457;

    private const int ACHIEVEMENT_KEY_FIELD_ID = 123458;

    private const int DONE_STATUS_ID = 4;

    private const int OPEN_STATUS_ID = 1;

    private const string ROD_OF_DISCORD = 'item:1326';

    private const string EYE_OF_CTHULHU = 'boss:eye_of_cthulhu';

    private FakeBacklog $backlog;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('backlog.base_url', self::BASE_URL);
        config()->set('backlog.api_key', 'feature-secret-api-key-abcdef123456');
        config()->set('backlog.project_key', self::PROJECT_KEY);
        config()->set('backlog.custom_fields', [
            'record_type' => ['id' => self::RECORD_TYPE_FIELD_ID, 'name' => 'Terraria Record Type'],
            'world_key' => ['id' => self::WORLD_KEY_FIELD_ID, 'name' => 'Terraria World Key'],
            'achievement_key' => ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'name' => 'Terraria Key'],
        ]);
        config()->set('backlog.done_status_id', self::DONE_STATUS_ID);
        config()->set('backlog.registry_issue_type_id', 11);
        config()->set('backlog.registry_priority_id', 3);
        config()->set('backlog.registry_lock.directory', storage_path('framework/testing/terraria-locks'));

        // ProjectConfiguration を先に解決して memo 化する。以降の Http::fake は
        // Registry / Mapping の呼び出しだけを観測する。
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
    }

    #[Test]
    public function a_collection_change_registers_the_item_and_acknowledges_the_triggering_player(): void
    {
        $response = $this->snapshot();

        $response->assertOk();
        $response->assertExactJson([
            'requestId' => SnapshotPayload::REQUEST_ID,
            'worldKey' => SnapshotPayload::WORLD_KEY,
            'notifications' => [
                [
                    'audience' => 'players',
                    'playerNames' => ['player1'],
                    'message' => '[Backlog] Rod of Discord を登録しました。取り出してOKです。',
                ],
            ],
        ]);

        // 有効 payload は downedBoss1 も立っているため Registry は Item / Boss の 2 件。
        // ACK は Item 分の 1 件だけであり、Boss は Player 通知にしない (§15)。
        $this->assertSame(2, $this->backlog->countRequests('POST', '/api/v2/issues'));
    }

    #[Test]
    public function the_response_never_carries_internal_backlog_detail(): void
    {
        $this->backlog->addRegistry(SnapshotPayload::WORLD_KEY, self::ROD_OF_DISCORD, done: true);
        $this->backlog->addPlainIssue(SnapshotPayload::WORLD_KEY, self::ROD_OF_DISCORD);

        $body = $this->snapshot()->assertOk()->getContent() ?: '';

        foreach ([
            'item:1326',
            'achievement',
            'issueKey',
            'issueId',
            'registry',
            'mapping',
            self::PROJECT_KEY,
            'customField',
            'statusId',
            'projectId',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                sprintf('response に内部 Backlog 情報 "%s" を含めてはいけない (docs/design.md §6.5)', $forbidden),
            );
        }
    }

    #[Test]
    public function a_periodic_sweep_completes_a_mapping_added_after_the_registry_was_stored(): void
    {
        // AC-06: Registry 保存済み。Item は既にチェストから取り出されている。
        $this->backlog->addRegistry(SnapshotPayload::WORLD_KEY, self::ROD_OF_DISCORD, done: true);
        $this->backlog->addPlainIssue(SnapshotPayload::WORLD_KEY, self::ROD_OF_DISCORD);

        $this->snapshot([
            'reason' => 'periodic',
            'collectionChests.0.items' => [],
            'flags' => ['downedBoss1' => false, 'hardMode' => false],
            'trigger' => null,
        ])->assertOk()->assertJsonPath('notifications', []);

        // Registry 作成が無いので、PATCH は攻略課題の完了 1 件だけのはず。
        $patches = $this->backlog->requestsMatching('PATCH', '/api/v2/issues/');
        $this->assertCount(1, $patches);
        $this->assertSame(self::DONE_STATUS_ID, (int) $patches[0]['form']['statusId']);
        $this->assertSame(0, $this->backlog->countRequests('POST', '/api/v2/issues'), 'Registry を作り直さない');
    }

    #[Test]
    public function a_manual_sync_reflects_a_post_hoc_mapping_immediately(): void
    {
        $this->backlog->addRegistry(SnapshotPayload::WORLD_KEY, self::EYE_OF_CTHULHU, done: true);
        $this->backlog->addPlainIssue(SnapshotPayload::WORLD_KEY, self::EYE_OF_CTHULHU);

        $this->snapshot([
            'reason' => 'manual',
            'collectionChests.0.items' => [],
            'flags' => ['downedBoss1' => false, 'hardMode' => false],
            'trigger' => null,
        ])->assertOk();

        $this->assertCount(1, $this->backlog->requestsMatching('PATCH', '/api/v2/issues/'));
    }

    #[Test]
    public function repeated_reconciliation_issues_no_duplicate_create_or_patch(): void
    {
        $this->backlog->addPlainIssue(SnapshotPayload::WORLD_KEY, self::ROD_OF_DISCORD);

        $this->snapshot(['reason' => 'periodic', 'trigger' => null])->assertOk();

        $writesAfterFirst = $this->backlog->writeCount();
        $this->assertGreaterThan(0, $writesAfterFirst);

        // AC-11: 同じ観測を繰り返しても 1 回も書き込まない。
        $this->snapshot(['reason' => 'periodic', 'trigger' => null])->assertOk();
        $this->snapshot(['reason' => 'periodic', 'trigger' => null])->assertOk();

        $this->assertSame(
            $writesAfterFirst,
            $this->backlog->writeCount(),
            '再照合で不要な create / PATCH を送信した',
        );
    }

    #[Test]
    public function a_backlog_outage_returns_a_retriable_failure_without_a_success_ack(): void
    {
        // Registry scan が 503。「該当 0 件」と読み替えず、成功 ACK も出さない。
        $this->backlog->interceptNext(
            'GET',
            '/api/v2/issues',
            static fn (): PromiseInterface => Http::response(['errors' => [['message' => 'service unavailable']]], 503),
        );

        $response = $this->snapshot();

        // AC-09 / AC-18: 非成功応答。Adapter はこれで復旧待ちフラグを立てる。
        $response->assertStatus(503);
        $this->assertArrayNotHasKey('notifications', (array) $response->json());
    }

    #[Test]
    public function a_recovery_sweep_emits_only_the_server_console_notice(): void
    {
        // 障害中に納品された Item がチェストに残っている状態からの復旧。
        $response = $this->snapshot([
            'reason' => 'periodic',
            'recoveryPending' => true,
            // 過去の操作 Player を Snapshot が運んでいても ACK は復元しない。
            'trigger' => ['playerNames' => ['player1']],
        ]);

        $response->assertOk();
        $response->assertExactJson([
            'requestId' => SnapshotPayload::REQUEST_ID,
            'worldKey' => SnapshotPayload::WORLD_KEY,
            'notifications' => [
                ['audience' => 'server', 'message' => BuildNotifications::RECOVERY_MESSAGE],
            ],
        ]);

        $body = $response->getContent() ?: '';
        $this->assertStringNotContainsString('player1', $body);
        $this->assertStringNotContainsString('Rod of Discord', $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function snapshot(array $overrides = []): TestResponse
    {
        return $this->postSnapshot(SnapshotPayload::with($overrides));
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
                ['id' => 11, 'projectId' => self::PROJECT_ID, 'name' => 'タスク'],
            ], 200),
            self::BASE_URL.'/api/v2/priorities*' => Http::response([
                ['id' => 3, 'name' => '中'],
            ], 200),
        ]);
    }
}
