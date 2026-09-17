<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\SynchronizeMappedIssues;
use App\Console\Commands\Support\RuntimeConfigurationChecker;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Registry\RegistryRepository;
use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\BacklogMappingRepository;
use App\Infrastructure\Backlog\BacklogRegistryRepository;
use App\Infrastructure\Backlog\IssueListPaginator;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use App\Infrastructure\Lock\CrossProcessLockFactory;
use App\Infrastructure\Lock\FileLockFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Backlog 連携 (Task 03) の DI 定義。
 *
 * `AppServiceProvider` は他 Task と共有するため、Backlog 固有の binding は
 * 本 Provider に閉じる。
 */
final class BacklogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BacklogClient::class, function ($app): BacklogClient {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new BacklogClient(
                http: $app->make(HttpFactory::class),
                baseUrl: rtrim((string) $config->get('backlog.base_url'), '/'),
                apiKey: (string) $config->get('backlog.api_key'),
                connectTimeout: (int) $config->get('backlog.timeout.connect'),
                requestTimeout: (int) $config->get('backlog.timeout.request'),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(IssueListPaginator::class, function ($app): IssueListPaginator {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new IssueListPaginator(
                client: $app->make(BacklogClient::class),
                pageSize: (int) $config->get('backlog.pagination.count'),
                maxPages: (int) $config->get('backlog.pagination.max_pages'),
            );
        });

        $this->app->singleton(ProjectConfigurationRepository::class, function ($app): ProjectConfigurationRepository {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            /** @var array<string, array{id: mixed, name: string}> $customFields */
            $customFields = (array) $config->get('backlog.custom_fields', []);

            return new ProjectConfigurationRepository(
                client: $app->make(BacklogClient::class),
                configuredProjectKey: (string) $config->get('backlog.project_key'),
                requiredProjectKey: (string) $config->get('backlog.required_project_key'),
                customFields: $customFields,
                textCustomFieldTypeId: (int) $config->get('backlog.custom_field_text_type_id'),
                doneStatusId: $config->get('backlog.done_status_id'),
                registryIssueTypeId: $config->get('backlog.registry_issue_type_id'),
                registryPriorityId: $config->get('backlog.registry_priority_id'),
            );
        });

        // docs/design.md §10.5: Registry 書き込みの cross-process file lock。
        $this->app->singleton(CrossProcessLockFactory::class, function ($app): CrossProcessLockFactory {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new FileLockFactory(
                directory: (string) $config->get('backlog.registry_lock.directory'),
                timeoutSeconds: (float) $config->get('backlog.registry_lock.timeout'),
            );
        });

        // docs/design.md §19.1: Domain 側は Registry の実体が Backlog Issue で
        // あることに依存しない。差し替え点を interface に固定する。
        $this->app->singleton(RegistryRepository::class, function ($app): RegistryRepository {
            return new BacklogRegistryRepository(
                projects: $app->make(ProjectConfigurationRepository::class),
                paginator: $app->make(IssueListPaginator::class),
                client: $app->make(BacklogClient::class),
                locks: $app->make(CrossProcessLockFactory::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        // docs/design.md §11, §19.1: Mapping も実体 (Backlog Issue) を Domain から隠す。
        $this->app->singleton(MappingRepository::class, function ($app): MappingRepository {
            return new BacklogMappingRepository(
                projects: $app->make(ProjectConfigurationRepository::class),
                paginator: $app->make(IssueListPaginator::class),
                client: $app->make(BacklogClient::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(SynchronizeMappedIssues::class, function ($app): SynchronizeMappedIssues {
            return new SynchronizeMappedIssues(
                mappings: $app->make(MappingRepository::class),
                logger: $app->make(LoggerInterface::class),
            );
        });

        $this->app->singleton(RuntimeConfigurationChecker::class, function ($app): RuntimeConfigurationChecker {
            return new RuntimeConfigurationChecker(
                config: $app->make(ConfigRepository::class),
                // docs/design.md §4: contracts/terraria/<version>/items.json が正本。
                // 通常は config('item_catalog.base_path') が優先され、これは
                // config/item_catalog.php ごと欠けている場合の最終 fallback。
                contractsCatalogPath: dirname($app->basePath()).'/contracts/terraria',
            );
        });
    }
}
