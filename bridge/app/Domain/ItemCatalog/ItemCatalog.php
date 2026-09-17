<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

/**
 * 特定 Terraria version の Item catalog (docs/design.md §6.4)。
 *
 * catalog は必ず version と組で扱い、runtime Terraria version と異なる catalog を
 * 暗黙に流用しない。
 */
final readonly class ItemCatalog
{
    /**
     * @param  array<int, ItemCatalogEntry>  $entries  Item.type をキーにした索引
     */
    private function __construct(
        public string $terrariaVersion,
        private array $entries,
    ) {}

    /**
     * @param  list<ItemCatalogEntry>  $entries
     *
     * @throws ItemCatalogUnavailable 同じ id が重複している場合
     */
    public static function fromEntries(string $terrariaVersion, array $entries): self
    {
        $indexed = [];

        foreach ($entries as $entry) {
            if (array_key_exists($entry->id, $indexed)) {
                throw ItemCatalogUnavailable::duplicateItemId($terrariaVersion, $entry->id);
            }

            $indexed[$entry->id] = $entry;
        }

        return new self($terrariaVersion, $indexed);
    }

    public function has(int $itemType): bool
    {
        return array_key_exists($itemType, $this->entries);
    }

    public function get(int $itemType): ?ItemCatalogEntry
    {
        return $this->entries[$itemType] ?? null;
    }

    public function count(): int
    {
        return count($this->entries);
    }
}
