<?php

declare(strict_types=1);

namespace Tests\Unit\Snapshot;

use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotResult;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * notification-only response contract の単体テスト
 * (docs/design.md §6.5 / §15.2, contracts/snapshot-response-v1.schema.json)。
 */
final class SnapshotNotificationTest extends TestCase
{
    #[Test]
    public function a_player_notification_carries_its_recipients(): void
    {
        $notification = SnapshotNotification::toPlayers(['player1', 'player2'], 'hello');

        $this->assertSame([
            'audience' => 'players',
            'playerNames' => ['player1', 'player2'],
            'message' => 'hello',
        ], $notification->toArray());
    }

    #[Test]
    public function a_server_console_notification_never_carries_player_names(): void
    {
        $notification = SnapshotNotification::toServerConsole('[Backlog] 復旧後の再同期が完了しました。');

        $this->assertSame([
            'audience' => 'server',
            'message' => '[Backlog] 復旧後の再同期が完了しました。',
        ], $notification->toArray());
        $this->assertArrayNotHasKey('playerNames', $notification->toArray());
    }

    #[Test]
    public function a_player_notification_without_recipients_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SnapshotNotification::toPlayers([], 'hello');
    }

    #[Test]
    public function an_empty_message_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SnapshotNotification::toServerConsole('');
    }

    #[Test]
    public function the_default_result_has_no_notifications(): void
    {
        $this->assertSame([], SnapshotResult::withoutNotifications()->toArray());
    }

    #[Test]
    public function a_world_key_must_be_a_usable_identifier(): void
    {
        $this->assertSame('terraria:123', WorldKey::fromString('terraria:123')->value);
        $this->assertTrue(WorldKey::fromString('terraria:123')->equals(WorldKey::fromString('terraria:123')));
        $this->assertFalse(WorldKey::fromString('terraria:123')->equals(WorldKey::fromString('terraria:124')));

        $this->expectException(InvalidArgumentException::class);
        WorldKey::fromString("terraria:123\n");
    }
}
