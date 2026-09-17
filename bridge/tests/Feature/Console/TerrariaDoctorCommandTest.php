<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `php artisan terraria:doctor` (docs/design.md §9.4, §18.4, AC-16/18/19)。
 *
 * 実 Backlog へは接続せず、HTTP Client fake のみで検証する。
 */
final class TerrariaDoctorCommandTest extends TestCase
{
    private const BASE_URL = 'https://backlog.test';

    private const API_KEY = 'doctor-secret-api-key-abcdef123456';

    private const PROJECT_ID = 4242;

    private const RECORD_TYPE_FIELD_ID = 123456;

    private const WORLD_KEY_FIELD_ID = 123457;

    private const ACHIEVEMENT_KEY_FIELD_ID = 123458;

    private const DONE_STATUS_ID = 4;

    private const ISSUE_TYPE_ID = 11;

    private const PRIORITY_ID = 3;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('backlog.base_url', self::BASE_URL);
        config()->set('backlog.api_key', self::API_KEY);
        config()->set('backlog.project_key', 'TRAINING_YOSHIZUMI');
        config()->set('backlog.custom_fields', [
            'record_type' => ['id' => self::RECORD_TYPE_FIELD_ID, 'name' => 'Terraria Record Type'],
            'world_key' => ['id' => self::WORLD_KEY_FIELD_ID, 'name' => 'Terraria World Key'],
            'achievement_key' => ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'name' => 'Terraria Key'],
        ]);
        config()->set('backlog.done_status_id', self::DONE_STATUS_ID);
        config()->set('backlog.registry_issue_type_id', self::ISSUE_TYPE_ID);
        config()->set('backlog.registry_priority_id', self::PRIORITY_ID);

        // Task 02 / Task 04 が所有する config/terraria.php 相当の値。
        // doctor は読むだけで、この Task では config/terraria.php を作らない。
        config()->set('terraria.allowed_world_keys', ['terraria:123456789']);
        config()->set('terraria.collection_chest_name', 'BACKLOG_COLLECTION');
        config()->set('terraria.supported_runtime', '4.3.13:1.3.0.8');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeHealthyBacklog(array $overrides = []): void
    {
        Http::fake(array_merge([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response(['id' => 1], 200),
            self::BASE_URL.'/api/v2/projects/TRAINING_YOSHIZUMI*' => Http::response([
                'id' => self::PROJECT_ID,
                'projectKey' => 'TRAINING_YOSHIZUMI',
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
        ], $overrides));
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runDoctor(): array
    {
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('terraria:doctor');

        return [$exitCode, Artisan::output()];
    }

    #[Test]
    public function it_succeeds_when_every_check_passes(): void
    {
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringNotContainsString('[ NG ]', $output);
        $this->assertStringNotContainsString('[SKIP]', $output);
    }

    #[Test]
    public function it_fails_when_the_project_key_is_not_the_fixed_value(): void
    {
        config()->set('backlog.project_key', 'TRAINING_SOMEONE_ELSE');
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.project_key', $output);
        $this->assertStringContainsString('TRAINING_YOSHIZUMI', $output);
        // 誤 Project へ一切問い合わせない。
        Http::assertNothingSent();
    }

    #[Test]
    public function it_fails_when_a_custom_field_is_missing(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Record Type'],
            ], 200),
        ]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.custom_field.world_key', $output);
    }

    #[Test]
    public function it_fails_when_a_custom_field_is_not_text_typed(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/'.self::PROJECT_ID.'/customFields*' => Http::response([
                ['id' => self::RECORD_TYPE_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 5, 'name' => 'Terraria Record Type'],
                ['id' => self::WORLD_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria World Key'],
                ['id' => self::ACHIEVEMENT_KEY_FIELD_ID, 'projectId' => self::PROJECT_ID, 'typeId' => 1, 'name' => 'Terraria Key'],
            ], 200),
        ]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.custom_field.record_type', $output);
    }

    #[Test]
    public function it_fails_when_the_done_status_is_not_valid(): void
    {
        config()->set('backlog.done_status_id', 99);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.done_status', $output);
    }

    #[Test]
    public function it_fails_when_the_registry_issue_type_is_not_valid(): void
    {
        config()->set('backlog.registry_issue_type_id', 99);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.registry_issue_type', $output);
    }

    #[Test]
    public function it_fails_when_the_registry_priority_is_not_valid(): void
    {
        config()->set('backlog.registry_priority_id', 99);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.registry_priority', $output);
    }

    #[Test]
    public function it_fails_when_the_backlog_api_is_unavailable(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response([], 503),
        ]);

        [$exitCode, $output] = $this->runDoctor();

        // 到達不能を「問題なし」と扱わない (docs/design.md §13.4)。
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.authentication', $output);
    }

    #[Test]
    public function it_fails_when_the_backlog_api_is_rate_limited(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/projects/TRAINING_YOSHIZUMI*' => Http::response([], 429),
        ]);

        [$exitCode] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
    }

    #[Test]
    public function it_fails_when_the_world_allowlist_is_missing(): void
    {
        config()->set('terraria.allowed_world_keys', []);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('terraria.world_allowlist', $output);
    }

    #[Test]
    public function it_fails_when_the_collection_chest_name_is_missing(): void
    {
        config()->set('terraria.collection_chest_name', null);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('terraria.collection_chest_name', $output);
    }

    #[Test]
    public function it_fails_when_the_supported_runtime_is_unknown(): void
    {
        config()->set('terraria.supported_runtime', '6.1.0:1.4.5.6');
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('terraria.supported_runtime', $output);
    }

    #[Test]
    public function it_fails_when_the_item_catalog_is_missing(): void
    {
        config()->set('terraria.item_catalog_path', base_path('tests/__no_such_catalog__'));
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('terraria.item_catalog', $output);
    }

    #[Test]
    public function it_fails_when_the_terraria_config_is_entirely_absent(): void
    {
        // Task 02 / Task 04 の config/terraria.php がまだ無い状態を模す。
        config()->set('terraria', null);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('terraria.world_allowlist', $output);
    }

    #[Test]
    public function it_never_prints_the_api_key(): void
    {
        // 成功時 / 失敗時 / API 障害時のいずれでも出力に API Key を含めない。
        $this->fakeHealthyBacklog();
        [, $successOutput] = $this->runDoctor();

        $this->assertStringNotContainsString(self::API_KEY, $successOutput);
        $this->assertStringNotContainsString('Backlog-API-Key', $successOutput);
        $this->assertStringContainsString('backlog.api_key', $successOutput);
    }

    #[Test]
    public function it_never_prints_the_api_key_when_backlog_rejects_authentication(): void
    {
        $this->fakeHealthyBacklog([
            self::BASE_URL.'/api/v2/users/myself*' => Http::response(['errors' => []], 401),
        ]);

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringNotContainsString(self::API_KEY, $output);
    }

    #[Test]
    public function it_fails_when_the_api_key_is_not_configured(): void
    {
        config()->set('backlog.api_key', null);
        $this->fakeHealthyBacklog();

        [$exitCode, $output] = $this->runDoctor();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('backlog.api_key', $output);
        Http::assertNothingSent();
    }

    #[Test]
    public function it_is_read_only_and_never_writes_to_backlog(): void
    {
        $this->fakeHealthyBacklog();

        $this->runDoctor();

        foreach (Http::recorded() as [$request]) {
            $this->assertSame('GET', $request->method(), 'doctor が GET 以外のリクエストを送っている。');
        }
    }
}
