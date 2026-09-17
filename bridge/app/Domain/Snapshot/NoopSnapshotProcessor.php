<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Task 02 時点の stub 実装 (tasks/02-snapshot-api-validation.md §5)。
 *
 * Application 処理をまだ持たないため、常に通知なしの結果を返す。
 * Backlog API 呼び出し・Registry・Mapping・Achievement 判定は行わない。
 */
final class NoopSnapshotProcessor implements SnapshotProcessor
{
    public function process(WorldSnapshot $snapshot): SnapshotResult
    {
        return SnapshotResult::withoutNotifications();
    }
}
