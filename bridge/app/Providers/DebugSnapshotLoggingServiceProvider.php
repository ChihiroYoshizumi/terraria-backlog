<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Debug\LoggingSnapshotProcessor;
use App\Domain\Snapshot\SnapshotProcessor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Task 08 の実機確認用。`TERRARIA_DEBUG_LOG_ACHIEVEMENTS=true` のときだけ
 * 診断専用の {@see LoggingSnapshotProcessor} を bind する。
 *
 * `SnapshotServiceProvider` は `bindIf` で no-op を既定に置くだけで、
 * この Provider はその後に登録されるため singleton() が上書きする。
 *
 * Task 07 が `ProcessWorldSnapshot` を実装したら、この Provider と
 * `LoggingSnapshotProcessor` は削除する。
 */
final class DebugSnapshotLoggingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);

        if ($config->get('terraria.debug_log_achievements') !== true) {
            return;
        }

        $this->app->singleton(SnapshotProcessor::class, LoggingSnapshotProcessor::class);
    }
}
