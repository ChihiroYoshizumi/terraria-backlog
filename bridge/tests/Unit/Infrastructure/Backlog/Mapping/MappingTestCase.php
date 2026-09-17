<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog\Mapping;

use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\BacklogMappingRepository;
use App\Infrastructure\Backlog\IssueListPaginator;
use App\Infrastructure\Backlog\ProjectConfiguration;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Unit\Support\RecordingLogger;

/**
 * Task 06 の共通セットアップ。
 *
 * 実 Backlog へは接続しない。`FakeMappingBacklog` は Issue List の絞り込みを
 * 無視して全件返すため、Project ID / Record Type / World Key / Terraria Key の
 * 一致判定はすべて PHP 側の final match が担う。
 */
abstract class MappingTestCase extends TestCase
{
    protected const BASE_URL = 'https://backlog.test';

    protected const API_KEY = 'mapping-secret-api-key-abcdef123456';

    protected const PROJECT_KEY = 'TRAINING_YOSHIZUMI';

    protected const PROJECT_ID = 4242;

    protected const OTHER_PROJECT_ID = 9999;

    protected const RECORD_TYPE_FIELD_ID = 123456;

    protected const WORLD_KEY_FIELD_ID = 123457;

    protected const ACHIEVEMENT_KEY_FIELD_ID = 123458;

    protected const DONE_STATUS_ID = 4;

    protected const OPEN_STATUS_ID = 1;

    protected const ISSUE_TYPE_ID = 11;

    protected const PRIORITY_ID = 3;

    protected const WORLD_A = 'terraria:111111111';

    protected const WORLD_B = 'terraria:222222222';

    protected FakeMappingBacklog $backlog;

    protected RecordingLogger $logger;

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
        // 以降のテストは Mapping 操作のリクエストだけを観測する。
        $this->fakeProjectConfigurationEndpoints();
        $this->app->make(ProjectConfigurationRepository::class)->resolve();

        $this->backlog = new FakeMappingBacklog(
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

        $this->logger = new RecordingLogger;
    }

    protected function repository(): BacklogMappingRepository
    {
        return new BacklogMappingRepository(
            projects: $this->app->make(ProjectConfigurationRepository::class),
            paginator: new IssueListPaginator($this->app->make(BacklogClient::class), 100, 200),
            client: $this->app->make(BacklogClient::class),
            logger: $this->logger,
        );
    }

    protected function configuration(): ProjectConfiguration
    {
        return $this->app->make(ProjectConfigurationRepository::class)->resolve();
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
}
