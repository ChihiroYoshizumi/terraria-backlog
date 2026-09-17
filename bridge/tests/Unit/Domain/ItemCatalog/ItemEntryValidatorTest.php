<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ItemCatalog;

use App\Domain\ItemCatalog\ItemCatalog;
use App\Domain\ItemCatalog\ItemCatalogEntry;
use App\Domain\ItemCatalog\ItemEntryValidator;
use App\Domain\ItemCatalog\ItemRejectionReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/design.md §6.4 「Item 単位で無視する validation」。
 */
final class ItemEntryValidatorTest extends TestCase
{
    private function catalog(): ItemCatalog
    {
        return ItemCatalog::fromEntries('1.3.0.8', [
            new ItemCatalogEntry(2, 'Dirt Block', 999),
            new ItemCatalogEntry(1326, 'Rod of Discord', 1),
        ]);
    }

    public function test_valid_item_is_accepted(): void
    {
        $result = (new ItemEntryValidator)->validate(['type' => 2, 'stack' => 42], $this->catalog());

        $this->assertTrue($result->valid);
        $this->assertSame(2, $result->itemType);
        $this->assertSame(42, $result->stack);
        $this->assertSame('Dirt Block', $result->entry?->name);
    }

    public function test_stack_boundaries_are_inclusive(): void
    {
        $validator = new ItemEntryValidator;

        $this->assertTrue($validator->validate(['type' => 2, 'stack' => 1], $this->catalog())->valid);
        $this->assertTrue($validator->validate(['type' => 2, 'stack' => 999], $this->catalog())->valid);
        $this->assertTrue($validator->validate(['type' => 1326, 'stack' => 1], $this->catalog())->valid);
    }

    public function test_unknown_item_id_is_skipped(): void
    {
        $result = (new ItemEntryValidator)->validate(['type' => 999999, 'stack' => 1], $this->catalog());

        $this->assertFalse($result->valid);
        $this->assertSame(ItemRejectionReason::TypeNotInCatalog, $result->reason);
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function nonIntegerTypes(): array
    {
        return [['2'], ['1326'], [2.0], [2.5], [true], [false], [null], [[2]], [['id' => 2]]];
    }

    #[DataProvider('nonIntegerTypes')]
    public function test_non_integer_type_is_skipped(mixed $type): void
    {
        $result = (new ItemEntryValidator)->validate(['type' => $type, 'stack' => 1], $this->catalog());

        $this->assertFalse($result->valid);
        $this->assertSame(ItemRejectionReason::TypeNotInteger, $result->reason);
    }

    /**
     * @return list<array{0: mixed, 1: ItemRejectionReason}>
     */
    public static function invalidStacks(): array
    {
        return [
            [0, ItemRejectionReason::StackOutOfRange],
            [-1, ItemRejectionReason::StackOutOfRange],
            [-999, ItemRejectionReason::StackOutOfRange],
            [1000, ItemRejectionReason::StackOutOfRange],   // catalog maxStack 999 超過
            [PHP_INT_MAX, ItemRejectionReason::StackOutOfRange],
            ['1', ItemRejectionReason::StackNotInteger],
            ['many', ItemRejectionReason::StackNotInteger],
            [1.0, ItemRejectionReason::StackNotInteger],
            [1.5, ItemRejectionReason::StackNotInteger],
            [true, ItemRejectionReason::StackNotInteger],
            [null, ItemRejectionReason::StackNotInteger],
            [[1], ItemRejectionReason::StackNotInteger],
        ];
    }

    #[DataProvider('invalidStacks')]
    public function test_invalid_stack_is_skipped(mixed $stack, ItemRejectionReason $reason): void
    {
        $result = (new ItemEntryValidator)->validate(['type' => 2, 'stack' => $stack], $this->catalog());

        $this->assertFalse($result->valid);
        $this->assertSame($reason, $result->reason);
    }

    public function test_max_stack_is_looked_up_per_item(): void
    {
        $validator = new ItemEntryValidator;

        // Rod of Discord は maxStack 1。Dirt Block の 999 を流用しない。
        $result = $validator->validate(['type' => 1326, 'stack' => 2], $this->catalog());

        $this->assertFalse($result->valid);
        $this->assertSame(ItemRejectionReason::StackOutOfRange, $result->reason);
    }

    /**
     * @return list<array{0: mixed}>
     */
    public static function nonObjectEntries(): array
    {
        return [[[1, 2]], ['item'], [1326], [1.0], [true], [null], [[['type' => 2]]]];
    }

    #[DataProvider('nonObjectEntries')]
    public function test_non_object_entry_is_skipped(mixed $entry): void
    {
        $result = (new ItemEntryValidator)->validate($entry, $this->catalog());

        $this->assertFalse($result->valid);
        $this->assertSame(ItemRejectionReason::EntryNotObject, $result->reason);
    }

    public function test_missing_fields_are_skipped(): void
    {
        $validator = new ItemEntryValidator;

        $this->assertSame(
            ItemRejectionReason::TypeMissing,
            $validator->validate(['stack' => 1], $this->catalog())->reason,
        );
        $this->assertSame(
            ItemRejectionReason::StackMissing,
            $validator->validate(['type' => 2], $this->catalog())->reason,
        );
        $this->assertSame(
            ItemRejectionReason::TypeMissing,
            $validator->validate([], $this->catalog())->reason,
        );
    }

    public function test_std_class_entries_are_accepted(): void
    {
        /** @var object{type: int, stack: int} $entry */
        $entry = json_decode('{"type": 2, "stack": 3}');

        $result = (new ItemEntryValidator)->validate($entry, $this->catalog());

        $this->assertTrue($result->valid);
        $this->assertSame(2, $result->itemType);
    }

    public function test_extra_diagnostic_fields_do_not_affect_validity(): void
    {
        $result = (new ItemEntryValidator)->validate(
            ['type' => 2, 'stack' => 3, 'name' => 'Totally Wrong Name', 'prefix' => 81],
            $this->catalog(),
        );

        $this->assertTrue($result->valid);
        $this->assertSame('Dirt Block', $result->entry?->name);
    }
}
