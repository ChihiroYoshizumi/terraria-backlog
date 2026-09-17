<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\BuildNotifications;
use App\Application\EvaluateAchievements;
use App\Application\ProcessWorldSnapshot;
use App\Application\SynchronizeMappedIssues;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Registry\RegistryRepository;
use App\Domain\Snapshot\SnapshotProcessor;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Task 07 (Reconciliation / 通知 / 障害復旧) の DI 設定。
 *
 * `SnapshotServiceProvider` は `bindIf` で no-op 実装を既定に置くだけなので、
 * ここで `singleton()` すると {@see ProcessWorldSnapshot} が優先される
 * (docs/design.md §19)。
 */
final class ReconciliationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BuildNotifications::class);

        $this->app->singleton(SnapshotProcessor::class, static function (Application $app): SnapshotProcessor {
            return new ProcessWorldSnapshot(
                achievements: $app->make(EvaluateAchievements::class),
                registries: $app->make(RegistryRepository::class),
                mappings: $app->make(MappingRepository::class),
                synchronize: $app->make(SynchronizeMappedIssues::class),
                notifications: $app->make(BuildNotifications::class),
                logger: $app->make(LoggerInterface::class),
            );
        });
    }
}
