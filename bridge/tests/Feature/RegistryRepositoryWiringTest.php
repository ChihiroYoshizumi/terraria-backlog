<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Registry\RegistryRepository;
use App\Infrastructure\Backlog\BacklogRegistryRepository;
use App\Infrastructure\Lock\CrossProcessLockFactory;
use App\Infrastructure\Lock\FileLockFactory;
use Tests\TestCase;

/**
 * BacklogServiceProvider の Registry 関連 binding (docs/design.md §19.1, §10.5)。
 *
 * DB は使わない (docs/design.md §2.1)。lock は OS の lock file であって
 * 永続 Queue ではない。
 */
final class RegistryRepositoryWiringTest extends TestCase
{
    public function test_container_resolves_the_backlog_registry_repository(): void
    {
        $repository = $this->app->make(RegistryRepository::class);

        $this->assertInstanceOf(BacklogRegistryRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(RegistryRepository::class));
    }

    public function test_the_lock_factory_is_a_shared_cross_process_file_lock(): void
    {
        $factory = $this->app->make(CrossProcessLockFactory::class);

        $this->assertInstanceOf(FileLockFactory::class, $factory);

        // 全 Worker が同じ directory を共有する必要があるため、
        // request ごとに変わらない固定パスであること。
        $name = FileLockFactory::lockName('terraria:1', 'item:1326');

        $this->assertSame($factory->pathFor($name), $this->app->make(CrossProcessLockFactory::class)->pathFor($name));
        $this->assertStringEndsWith($name.'.lock', $factory->pathFor($name));
        $this->assertSame(
            (string) config('backlog.registry_lock.directory'),
            dirname($factory->pathFor($name)),
        );
    }
}
