<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Snapshot\NoopSnapshotProcessor;
use App\Domain\Snapshot\SnapshotProcessor;
use App\Domain\Snapshot\SnapshotRequestParser;
use App\Domain\Snapshot\SnapshotValidationSettings;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Task 02 (Snapshot API) の DI 設定。
 *
 * `AppServiceProvider` は複数 Task が触る共有ファイルなので、
 * Snapshot 境界の binding はこの Provider に閉じる。
 */
final class SnapshotServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // singleton にしない: 設定変更 (テストの config()->set 等) を取りこぼさない。
        $this->app->bind(SnapshotValidationSettings::class, static function ($app): SnapshotValidationSettings {
            /** @var ConfigRepository $config */
            $config = $app->make('config');

            /** @var array<string, mixed> $terraria */
            $terraria = $config->get('terraria', []);

            return SnapshotValidationSettings::fromConfig($terraria);
        });

        $this->app->bind(SnapshotRequestParser::class, static fn ($app): SnapshotRequestParser => new SnapshotRequestParser(
            $app->make(SnapshotValidationSettings::class),
        ));

        // Task 04 以降が `App\Application\ProcessWorldSnapshot` を
        // SnapshotProcessor として bind したら、そちらが優先される。
        $this->app->bindIf(SnapshotProcessor::class, NoopSnapshotProcessor::class);
    }
}
