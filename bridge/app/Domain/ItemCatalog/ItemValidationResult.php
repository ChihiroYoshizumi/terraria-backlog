<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

/**
 * Item 1 件の validation 結果 (docs/design.md §6.4)。
 */
final readonly class ItemValidationResult
{
    private function __construct(
        public bool $valid,
        public ?int $itemType,
        public ?int $stack,
        public ?ItemCatalogEntry $entry,
        public ?ItemRejectionReason $reason,
        public ?string $detail,
    ) {}

    public static function valid(int $itemType, int $stack, ItemCatalogEntry $entry): self
    {
        return new self(true, $itemType, $stack, $entry, null, null);
    }

    public static function rejected(ItemRejectionReason $reason, ?string $detail = null, ?int $itemType = null): self
    {
        return new self(false, $itemType, null, null, $reason, $detail);
    }
}
