<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

/**
 * Item 単位 validation で Achievement 候補から外した理由 (docs/design.md §6.4)。
 *
 * `item.invalid_skipped` の診断ログに出す。Snapshot 全体は失敗させない。
 */
enum ItemRejectionReason: string
{
    case EntryNotObject = 'entry_not_object';
    case TypeMissing = 'type_missing';
    case TypeNotInteger = 'type_not_integer';
    case TypeNotInCatalog = 'type_not_in_catalog';
    case StackMissing = 'stack_missing';
    case StackNotInteger = 'stack_not_integer';
    case StackOutOfRange = 'stack_out_of_range';
}
