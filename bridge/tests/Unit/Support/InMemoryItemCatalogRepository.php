<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\ItemCatalog\ItemCatalog;
use App\Domain\ItemCatalog\ItemCatalogEntry;
use App\Domain\ItemCatalog\ItemCatalogRepository;
use App\Domain\ItemCatalog\ItemCatalogUnavailable;

/**
 * ファイル I/O なしで catalog を差し替えるためのテスト用 repository。
 */
final class InMemoryItemCatalogRepository implements ItemCatalogRepository
{
    /**
     * @param  array<int, array{0: string, 1: int}>  $items  id => [name, maxStack]
     */
    public function __construct(
        private readonly string $terrariaVersion,
        private readonly array $items,
    ) {}

    public function forVersion(string $terrariaVersion): ItemCatalog
    {
        if ($terrariaVersion !== $this->terrariaVersion) {
            throw ItemCatalogUnavailable::fileMissing($terrariaVersion, '(in-memory)');
        }

        $entries = [];
        foreach ($this->items as $id => [$name, $maxStack]) {
            $entries[] = new ItemCatalogEntry($id, $name, $maxStack);
        }

        return ItemCatalog::fromEntries($terrariaVersion, $entries);
    }
}
