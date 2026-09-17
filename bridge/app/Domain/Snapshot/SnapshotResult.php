<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Application 処理の結果として Adapter へ返せるもの (docs/design.md §6.5)。
 *
 * **notification-only**。Achievement Key・Registry 結果・Backlog Issue Key・
 * Mapping 結果をこの型に足してはならない。それらは PHP の構造化ログで診断する。
 */
final readonly class SnapshotResult
{
    /**
     * @param  list<SnapshotNotification>  $notifications
     */
    public function __construct(public array $notifications = []) {}

    public static function withoutNotifications(): self
    {
        return new self([]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(
            static fn (SnapshotNotification $notification): array => $notification->toArray(),
            $this->notifications,
        );
    }
}
