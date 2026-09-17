<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use LogicException;

/**
 * Collection Chest 内で観測した Item 1 件 (docs/design.md §6.2, §6.4)。
 *
 * ここが Task 02 (request-level validation) と Task 04 (Item catalog 判定) の境界。
 *
 * - Task 02 は **構造レベル** だけを判定する: entry が object か、`type` / `stack` が
 *   JSON integer か、`stack >= 1` か。判定結果は {@see rejections} に蓄積し、
 *   不正であっても Snapshot 全体を reject しない (docs/design.md §18.3)。
 * - Task 04 は catalog 照合 (`type` の実在、`stack <= maxStack`) を行い、
 *   {@see withRejection()} で理由を追加してから Achievement 候補を決める。
 *
 * 型 coercion は一切行わない。`"1326"` や `1326.0` は integer ではないため reject する。
 */
final readonly class ObservedItem
{
    /**
     * @param  int  $index  chest の items 配列内での位置 (診断ログ用)
     * @param  mixed  $rawType  受信したままの `type`
     * @param  mixed  $rawStack  受信したままの `stack`
     * @param  list<ItemRejectionReason>  $rejections
     */
    public function __construct(
        public int $index,
        public mixed $rawType,
        public mixed $rawStack,
        public ?string $name,
        public array $rejections,
    ) {}

    public function isAccepted(): bool
    {
        return $this->rejections === [];
    }

    /**
     * 構造レベルで有効な Item の `type`。無効な Item では呼べない。
     */
    public function type(): int
    {
        if (! is_int($this->rawType)) {
            throw new LogicException('ObservedItem::type() is only available for structurally valid items.');
        }

        return $this->rawType;
    }

    /**
     * 構造レベルで有効な Item の `stack`。無効な Item では呼べない。
     */
    public function stack(): int
    {
        if (! is_int($this->rawStack)) {
            throw new LogicException('ObservedItem::stack() is only available for structurally valid items.');
        }

        return $this->rawStack;
    }

    /**
     * Task 04 が catalog 照合の結果を追加するための API。
     */
    public function withRejection(ItemRejectionReason $reason): self
    {
        if (in_array($reason, $this->rejections, true)) {
            return $this;
        }

        return new self(
            $this->index,
            $this->rawType,
            $this->rawStack,
            $this->name,
            [...$this->rejections, $reason],
        );
    }

    /**
     * 診断ログ `item.invalid_skipped` 用。秘密情報や巨大な値を載せない。
     *
     * @return array<string, mixed>
     */
    public function diagnosticContext(): array
    {
        return [
            'item_index' => $this->index,
            'raw_type' => get_debug_type($this->rawType),
            'raw_stack' => get_debug_type($this->rawStack),
            'reasons' => array_map(
                static fn (ItemRejectionReason $reason): string => $reason->value,
                $this->rejections,
            ),
        ];
    }
}
