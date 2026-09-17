<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationReport;
use App\Infrastructure\Backlog\Exceptions\ProjectConfigurationException;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/design.md §9.1〜§9.4 / §18.4 の fail closed 検証。
 */
final class ProjectConfigurationRepositoryTest extends TestCase
{
    private const BASE_URL = 'https://backlog.test';

    private const PROJECT_ID = 4242;

    private const RECORD_TYPE_FIELD_ID = 123456;

    private const WORLD_KEY_FIELD_ID = 123457;

    private const ACHIEVEMENT_KEY_FIELD_ID = 123458;

    private const DONE_STATUS_ID = 4;

    private const ISSUE_TYPE_ID = 11;

    private const PRIORITY_ID = 3;

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function repository(array $overrides = []): ProjectConfigurationRepository
    {
        $client = new BacklogClient(
            http: $this->app->make(HttpFactory::class),
            baseUrl: self::BASE_URL,
            apiKey: $overrides['apiKey'] ?? 'test-api-key',
            connectTimeout: 2,
            requestTimeout: 8,
            logger: new RecordingLogger,
        );

        return new ProjectConfigurationRepository(
            client: $client,
            configuredProjectKey: $overrides['projectKey'] ?? 'TRAINING_YOSHIZUMI',
            requiredProjectKey: 'TRAINING_YOSHIZUMI',
            customFields: $overrides['customFields'] ?? [
                'record_type' => ['id' => self::RECORD_TYPE_FIELD_ID, 'name' => 'Terraria Record Type'],
                'world_key' => ['id' => self::WORLD_KEY_FIELD_ID, 'name' => 'Terraria World Key'],
                'achievement_key' => ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'name' => 'Terraria Key'],
            ],
            textCustomFieldTypeId: 1,
            doneStatusId: $overrides['doneStatusId'] ?? self::DONE_STATUS_ID,
            registryIssueTypeId: $overrides['issueTypeId'] ?? self::ISSUE_TYPE_ID,
            registryPriorityId: $overrides['priorityId'] ?? self::PRIORITY_ID,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeHealthyBacklog(array $overrides = []): void
    {
        Http::fake(array_merge([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response(['id' => 1, 'userId' => 'bot'], 200),
            self::BASE_URL.'/api/v2/projects/TRAINING_YOSHIZUMI*' => Http::response([
                'id' => self::PROJECT_ID,
                'projectKey' => 'TRAINING_YOSHIZUMI',
                'name' => '研修_吉住',
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/statuses*' => Http::response([
                ['id' => 1, 'projectId' => self::PROJECT_ID, 'name' => '未対応'],
                ['id' => self::DONE_STATUS_ID, 'projectId' => self::PROJECT_ID, 'name' => '完了'],
            ], 200),
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/issueTypes*' => Http::response([
                ['id' => self::ISSUE_TYPE_ID, 'projectId' => self::PROJECT_ID, 'name' => 'タスク'],
            ], 200),
            self::BASE_URL.'/api/v2/priorities*' => Http::response([
                ['id' => 2, 'name' => '高'],
                ['id' => self::PRIORITY_ID, 'name' => '中'],
            ], 200),
        ], $overrides));
    }

    private function checkNamed(ConfigurationReport $report, string $name): ConfigurationCheck
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        $this->fail(sprintf('検証項目 "%s" が report に存在しない。', $name));
    }

    #[Test]
    public function it_resolves_every_id_when_the_project_is_configured_correctly(): void
    {
        $this->fakeHealthyBacklog();

        $repository = $this->repository();
        $report = $repository->verify();

        $this->assertTrue($report->isSatisfied(), 'verify() が失敗した: '.json_encode($report->failures()));

        $configuration = $repository->resolve();

        $this->assertSame(self::PROJECT_ID, $configuration->projectId);
        $this->assertSame('TRAINING_YOSHIZUMI', $configuration->projectKey);
        $this->assertSame(self::RECORD_TYPE_FIELD_ID, $configuration->recordTypeFieldId);
        $this->assertSame(self::WORLD_KEY_FIELD_ID, $configuration->worldKeyFieldId);
        $this->assertSame(self::ACHIEVEMENT_KEY_FIELD_ID, $configuration->achievementKeyFieldId);
        $this->assertSame(self::DONE_STATUS_ID, $configuration->doneStatusId);
        $this->assertSame(self::ISSUE_TYPE_ID, $configuration->registryIssueTypeId);
        $this->assertSame(self::PRIORITY_ID, $configuration->registryPriorityId);
    }

    #[Test]
    public function it_fails_and_touches_no_project_when_the_project_key_is_not_the_fixed_value(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['projectKey' => 'SOME_OTHER_PROJECT'])->verify();

        $this->assertFalse($report->isSatisfied());
        $this->assertFalse($this->checkNamed($report, 'backlog.project_key')->isSatisfied());
        // 誤 Project へ書き込まないため、Backlog API を1回も呼ばない。
        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_when_the_project_key_is_missing(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['projectKey' => ''])->verify();

        $this->assertFalse($report->isSatisfied());
        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_when_the_api_key_is_missing(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['apiKey' => ''])->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.api_key')->isSatisfied());
        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_when_authentication_is_rejected(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response(['errors' => []], 401),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.authentication')->isSatisfied());
        // 認証できないとき、後続項目を OK と扱わない。
        $this->assertSame(
            ConfigurationCheck::STATUS_SKIPPED,
            $this->checkNamed($report, 'backlog.project')->status,
        );
    }

    #[Test]
    public function it_fails_when_the_project_cannot_be_resolved(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/TRAINING_YOSHIZUMI*' => Http::response(['errors' => []], 404),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.project')->isSatisfied());
        $this->assertSame(
            ConfigurationCheck::STATUS_SKIPPED,
            $this->checkNamed($report, 'backlog.custom_field.world_key')->status,
        );
    }

    #[Test]
    public function it_fails_when_backlog_returns_a_different_project(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/TRAINING_YOSHIZUMI*' => Http::response([
                'id' => 999,
                'projectKey' => 'ANOTHER_PROJECT',
            ], 200),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.project')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_a_custom_field_is_absent(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
            ], 200),
        ]);

        $report = $this->repository()->verify();

        $this->assertTrue($this->checkNamed($report, 'backlog.custom_field.record_type')->isSatisfied());
        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.world_key')->isSatisfied());
        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.achievement_key')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_a_custom_field_is_not_a_text_field(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
                // typeId=2 は Sentence (TextArea)。
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 2, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.world_key')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_a_custom_field_belongs_to_another_project(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => 777, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.achievement_key')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_a_custom_field_id_points_at_a_differently_named_field(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => '研修メモ'],
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.record_type')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_a_custom_field_id_is_not_configured(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository([
            'customFields' => [
                'record_type' => ['id' => null, 'name' => 'Terraria Record Type'],
                'world_key' => ['id' => self::WORLD_KEY_FIELD_ID, 'name' => 'Terraria World Key'],
                'achievement_key' => ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'name' => 'Terraria Key'],
            ],
        ])->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.record_type')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_the_done_status_is_not_valid_for_the_project(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['doneStatusId' => 99])->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.done_status')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_the_registry_issue_type_is_not_valid(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['issueTypeId' => 99])->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.registry_issue_type')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_the_registry_priority_is_not_valid(): void
    {
        $this->fakeHealthyBacklog();

        $report = $this->repository(['priorityId' => 99])->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.registry_priority')->isSatisfied());
    }

    #[Test]
    public function it_does_not_treat_a_rate_limited_custom_field_lookup_as_a_valid_configuration(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([], 429),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($report->isSatisfied());
        $this->assertFalse($this->checkNamed($report, 'backlog.custom_field.record_type')->isSatisfied());
    }

    #[Test]
    public function it_does_not_treat_a_server_error_on_the_status_list_as_a_valid_configuration(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/statuses*' => Http::response([], 500),
        ]);

        $report = $this->repository()->verify();

        $this->assertFalse($this->checkNamed($report, 'backlog.done_status')->isSatisfied());
    }

    #[Test]
    public function it_refuses_to_resolve_when_verification_failed(): void
    {
        $this->fakeHealthyBacklog();

        $repository = $this->repository(['projectKey' => 'SOME_OTHER_PROJECT']);

        $this->expectException(ProjectConfigurationException::class);

        $repository->resolve();
    }

    #[Test]
    public function it_memoizes_only_successful_resolution(): void
    {
        $this->fakeHealthyBacklog();

        $repository = $this->repository();

        $first = $repository->resolve();
        $sentAfterFirst = count(Http::recorded());
        $second = $repository->resolve();

        $this->assertSame($first, $second);
        $this->assertCount($sentAfterFirst, Http::recorded(), '2回目の resolve() で再度 API を呼んでいる。');
    }
}
