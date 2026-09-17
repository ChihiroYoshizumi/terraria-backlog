<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

/**
 * version-pinned Item catalog の 1 エントリ (contracts/items-v1.schema.json)。
 */
final readonly class ItemCatalogEntry
{
    public function __construct(
        public int $id,
        public string $name,
        public int $maxStack,
    ) {}
}
