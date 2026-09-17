<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Application 処理の結果として Adapter へ返せるもの (docs/design.md §6.5)。
 *
 * **notification-only**。Achievement Key・Registry 結果・Backlog Issue Key・
 * Mapping 結果をこの型に足してはならない。それらは PHP の構造化ログで診断する。
 *
 * {@see $outcome} は body ではなく HTTP status にだけ反映する
 * (docs/design.md §15.3, §18.2)。`toArray()` には現れない。
 */
final readonly class SnapshotResult
{
    /**
     * @param  list<SnapshotNotification>  $notifications
     */
    public function __construct(
        public array $notifications = [],
        public SnapshotOutcome $outcome = SnapshotOutcome::Completed,
    ) {}

    public static function withoutNotifications(): self
    {
        return new self([]);
    }

    /**
     * 再照合を完了できず、返せる通知も無かった場合 (docs/design.md §18.2)。
     */
    public static function retriableFailure(): self
    {
        return new self([], SnapshotOutcome::RetriableFailure);
    }

    public function httpStatus(): int
    {
        return $this->outcome->httpStatus();
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
