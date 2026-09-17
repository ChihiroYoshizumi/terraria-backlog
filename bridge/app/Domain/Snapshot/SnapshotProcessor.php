<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * request-level validation を通過した Snapshot を受け取る後続処理の入口。
 *
 * Task 02 の責務はここまで。実際の Achievement 判定 (Task 04)、
 * Registry 保存 (Task 05)、Mapping 同期 (Task 06)、通知生成 (Task 07) は
 * `App\Application\ProcessWorldSnapshot` がこの interface を実装して差し替える
 * (docs/design.md §19)。
 *
 * 差し替えは自前の ServiceProvider で
 * `$this->app->singleton(SnapshotProcessor::class, ProcessWorldSnapshot::class)`
 * を行う。`App\Providers\SnapshotServiceProvider` は `bindIf` で
 * no-op 実装を既定に置くだけなので、先に bind した実装が優先される。
 */
interface SnapshotProcessor
{
    public function process(WorldSnapshot $snapshot): SnapshotResult;
}
