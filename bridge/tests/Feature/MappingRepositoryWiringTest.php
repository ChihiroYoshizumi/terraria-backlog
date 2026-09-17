<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\SynchronizeMappedIssues;
use App\Domain\Mapping\MappingRepository;
use App\Infrastructure\Backlog\BacklogMappingRepository;
use Tests\TestCase;

/**
 * BacklogServiceProvider の Mapping 関連 binding (docs/design.md §11, §19.1)。
 */
final class MappingRepositoryWiringTest extends TestCase
{
    public function test_container_resolves_the_backlog_mapping_repository(): void
    {
        $repository = $this->app->make(MappingRepository::class);

        $this->assertInstanceOf(BacklogMappingRepository::class, $repository);
        $this->assertSame($repository, $this->app->make(MappingRepository::class));
    }

    public function test_container_resolves_the_synchronize_mapped_issues_use_case(): void
    {
        $synchronizer = $this->app->make(SynchronizeMappedIssues::class);

        $this->assertInstanceOf(SynchronizeMappedIssues::class, $synchronizer);
        $this->assertSame($synchronizer, $this->app->make(SynchronizeMappedIssues::class));
    }
}
