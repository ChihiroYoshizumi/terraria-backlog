<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\EvaluateAchievements;
use App\Domain\Achievement\WorldFlagEvaluator;
use App\Domain\ItemCatalog\ItemCatalogRepository;
use App\Domain\ItemCatalog\ItemEntryValidator;
use App\Domain\ItemCatalog\JsonFileItemCatalogRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Task 04 (Achievement 評価 / Item catalog) の束縛。
 */
final class AchievementServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // catalog は同一 version を読み直さないよう singleton にする
        // (プロセス内キャッシュのみ。永続ストアは追加しない)。
        $this->app->singleton(ItemCatalogRepository::class, static function (Application $app): ItemCatalogRepository {
            /** @var string $basePath */
            $basePath = $app->make('config')->get('item_catalog.base_path');

            return new JsonFileItemCatalogRepository($basePath);
        });

        $this->app->singleton(WorldFlagEvaluator::class);
        $this->app->singleton(ItemEntryValidator::class);

        $this->app->singleton(EvaluateAchievements::class, static function (Application $app): EvaluateAchievements {
            return new EvaluateAchievements(
                $app->make(ItemCatalogRepository::class),
                $app->make(WorldFlagEvaluator::class),
                $app->make(ItemEntryValidator::class),
                $app->make(LoggerInterface::class),
            );
        });
    }
}
