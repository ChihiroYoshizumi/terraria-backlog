<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotProcessor;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「response に Achievement Key / Issue Key / Mapping result が存在しない」。
 *
 * docs/design.md §6.5 と contracts/snapshot-response-v1.schema.json:
 * Adapter へ返すのは requestId / worldKey / notifications のみ。
 */
final class SnapshotResponseContractTest extends SnapshotTestCase
{
    #[Test]
    public function a_successful_snapshot_returns_only_the_notification_only_envelope(): void
    {
        $this->recordProcessor();

        $response = $this->postSnapshot(SnapshotPayload::valid());

        $response->assertOk();
        $response->assertExactJson([
            'requestId' => SnapshotPayload::REQUEST_ID,
            'worldKey' => SnapshotPayload::WORLD_KEY,
            'notifications' => [],
        ]);

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json();
        $this->assertSame(['requestId', 'worldKey', 'notifications'], array_keys($decoded));
    }

    #[Test]
    public function an_application_layer_without_notifications_returns_an_empty_array(): void
    {
        // 通知が不要な Snapshot では notifications は空配列 (docs/design.md §6.5)。
        // Task 07 以降の既定 processor は ProcessWorldSnapshot だが、ここで見たいのは
        // controller の envelope だけなので Application 層は差し替える。
        $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::valid())
            ->assertOk()
            ->assertExactJson([
                'requestId' => SnapshotPayload::REQUEST_ID,
                'worldKey' => SnapshotPayload::WORLD_KEY,
                'notifications' => [],
            ]);
    }

    #[Test]
    public function notifications_follow_the_response_schema_and_leak_no_registry_detail(): void
    {
        $this->app->instance(SnapshotProcessor::class, new RecordingSnapshotProcessor([
            SnapshotNotification::toPlayers(['player1'], '[Backlog] Rod of Discord を登録しました。取り出してOKです。'),
            SnapshotNotification::toServerConsole('[Backlog] 復旧後の再同期が完了しました。'),
        ]));

        $response = $this->postSnapshot(SnapshotPayload::valid());

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
                [
                    // audience=server は playerNames を持たない (§15.2)。
                    'audience' => 'server',
                    'message' => '[Backlog] 復旧後の再同期が完了しました。',
                ],
            ],
        ]);

        /** @var array<int, array<string, mixed>> $notifications */
        $notifications = $response->json('notifications');
        $this->assertSame(['audience', 'playerNames', 'message'], array_keys($notifications[0]));
        $this->assertSame(['audience', 'message'], array_keys($notifications[1]));
    }

    #[Test]
    public function the_response_never_exposes_achievement_registry_or_mapping_detail(): void
    {
        $this->recordProcessor();

        $response = $this->postSnapshot(SnapshotPayload::valid());
        $body = $response->getContent() ?: '';

        foreach ([
            'achievement',
            'achievementKey',
            'issueKey',
            'issueId',
            'registry',
            'mapping',
            'projectKey',
            'customField',
            'backlog',
        ] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase(
                $forbidden,
                $body,
                sprintf('response must not expose "%s" (docs/design.md §6.5)', $forbidden),
            );
        }
    }
}
