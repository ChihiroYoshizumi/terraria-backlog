<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Item 単位で無視する validation の理由 (docs/design.md §6.4 / §18.3)。
 *
 * これらは **Snapshot 全体を reject しない**。診断ログ `item.invalid_skipped` に
 * 残したうえで、その Item だけを Achievement 候補から除外する。
 *
 * Task 02 が判定するのは構造レベルの 4 ケースのみ。
 * catalog 照合を要する 2 ケース (TypeNotInCatalog / StackExceedsMaxStack) は
 * Task 04 の Item catalog 実装が使うために予約してある。
 */
enum ItemRejectionReason: string
{
    /** Item entry が JSON object でない。 */
    case NotAnObject = 'not_an_object';

    /** `type` が存在しない、または JSON integer でない (型 coercion は行わない)。 */
    case TypeNotInteger = 'type_not_integer';

    /** `stack` が存在しない、または JSON integer でない。 */
    case StackNotInteger = 'stack_not_integer';

    /** `stack` が 1 未満 (下限は catalog に依存しないため Task 02 で判定する)。 */
    case StackNotPositive = 'stack_not_positive';

    /** Task 04 用: `type` が採用 Terraria version の Item catalog に存在しない。 */
    case TypeNotInCatalog = 'type_not_in_catalog';

    /** Task 04 用: `stack` が catalog[type].maxStack を超える。 */
    case StackExceedsMaxStack = 'stack_exceeds_max_stack';
}
