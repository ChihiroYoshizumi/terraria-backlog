<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「禁止 field を操作先として信用しない」。
 *
 * docs/design.md §16.3 / docs/spec.md §10:
 * URL・Backlog Space・Project Key・Issue Key・Custom Field ID・Status ID は
 * PHP 設定からのみ取得し、payload の値に従って外部へ書き込まない。
 */
final class SnapshotInputTrustTest extends SnapshotTestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function forbiddenTargets(): array
    {
        return [
            'top-level backlog url' => [['url' => 'https://evil.example/api/v2']],
            'top-level api base' => [['apiUrl' => 'https://evil.example']],
            'top-level webhook' => [['webhookUrl' => 'https://evil.example/hook']],
            'top-level project key' => [['projectKey' => 'OTHER_PROJECT']],
            'snake_case project key' => [['project_key' => 'OTHER_PROJECT']],
            'top-level issue key' => [['issueKey' => 'OTHER_PROJECT-1']],
            'top-level custom field id' => [['customFieldId' => 9999]],
            'top-level status id' => [['statusId' => 4]],
            'top-level space key' => [['spaceKey' => 'evil']],
            'top-level api key' => [['apiKey' => 'leaked-backlog-key']],
            'nested under world' => [['world.projectKey' => 'OTHER_PROJECT']],
            'nested under trigger' => [['trigger.issueKey' => 'OTHER_PROJECT-1']],
            'nested under a chest' => [['collectionChests.0.statusId' => 4]],
            'nested under an item' => [['collectionChests.0.items.0.issueKey' => 'OTHER_PROJECT-1']],
            'nested two levels deep' => [['world.meta' => ['customFieldId' => 1]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('forbiddenTargets')]
    public function it_rejects_a_payload_that_names_an_external_write_target(array $overrides): void
    {
        $processor = $this->recordProcessor();

        $response = $this->postSnapshot(SnapshotPayload::with($overrides));

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', 'input.forbidden_field');

        // Registry / Mapping の処理を開始しない。
        $this->assertSame([], $processor->received);

        // 受信した値をそのまま返さない (エコーバック経由の誘導を避ける)。
        $body = $response->getContent() ?: '';
        foreach (['https://evil.example', 'OTHER_PROJECT', 'leaked-backlog-key'] as $injected) {
            $this->assertStringNotContainsString($injected, $body);
        }
    }

    #[Test]
    public function the_forbidden_key_list_is_configurable_and_actually_enforced(): void
    {
        // denylist を空にすると通ってしまうことを示し、ガードが実在することを固定する。
        config()->set('terraria.forbidden_payload_keys', []);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['projectKey' => 'OTHER_PROJECT']))->assertOk();
        $this->assertCount(1, $processor->received);

        // DTO には禁止 field が一切載っていない。後続 Task は payload から
        // 外部操作先を読み出せない (docs/design.md §16.3)。
        $snapshot = $processor->last();
        $this->assertSame(SnapshotPayload::WORLD_KEY, $snapshot->worldKey()->value);
        $this->assertObjectNotHasProperty('projectKey', $snapshot);
        $this->assertObjectNotHasProperty('url', $snapshot);
    }

    #[Test]
    public function the_default_configuration_denies_every_target_listed_in_design_16_3(): void
    {
        /** @var array<string, mixed> $defaults */
        $defaults = require base_path('config/terraria.php');

        /** @var list<string> $denied */
        $denied = $defaults['forbidden_payload_keys'];

        foreach (['url', 'spacekey', 'projectkey', 'issuekey', 'customfieldid', 'statusid', 'apikey'] as $expected) {
            $this->assertContains($expected, $denied);
        }
    }
}
