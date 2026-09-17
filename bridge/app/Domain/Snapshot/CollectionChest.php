<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * 観測した Collection Chest 1 件 (docs/spec.md §6, docs/design.md §6.2)。
 *
 * 座標は観測情報であり恒久的な識別条件にしない (docs/spec.md §6)。
 * 診断ログで「どの chest の何番目の Item か」を示すためだけに保持する。
 */
final readonly class CollectionChest
{
    /**
     * @param  list<ObservedItem>  $items
     */
    public function __construct(
        public int $x,
        public int $y,
        public string $name,
        public array $items,
    ) {}

    /**
     * 構造レベルで有効な Item のみ。catalog 照合は Task 04 が行う。
     *
     * @return list<ObservedItem>
     */
    public function acceptedItems(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ObservedItem $item): bool => $item->isAccepted(),
        ));
    }

    /**
     * @return list<ObservedItem>
     */
    public function rejectedItems(): array
    {
        return array_values(array_filter(
            $this->items,
            static fn (ObservedItem $item): bool => ! $item->isAccepted(),
        ));
    }
}
