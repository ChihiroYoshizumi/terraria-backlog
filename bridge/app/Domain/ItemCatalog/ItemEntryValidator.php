<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

use stdClass;

/**
 * Snapshot の Item 1 件を Achievement 候補として採用できるか判定する
 * (docs/design.md §6.4 「Item 単位で無視する validation」/ §8.3)。
 *
 * 型 coercion は一切行わない。次をすべて満たす Item だけが valid:
 *
 * - Item entry が object である
 * - `type` が JSON integer
 * - `type` が採用 Terraria version の catalog に存在する
 * - `stack` が JSON integer
 * - `1 <= stack <= catalog[type].maxStack`
 *
 * 満たさない Item は「その Item だけ」無視する。Snapshot 全体は失敗させない。
 */
final class ItemEntryValidator
{
    public function validate(mixed $entry, ItemCatalog $catalog): ItemValidationResult
    {
        $fields = self::asObjectFields($entry);

        if ($fields === null) {
            return ItemValidationResult::rejected(
                ItemRejectionReason::EntryNotObject,
                'item entry is '.get_debug_type($entry),
            );
        }

        if (! array_key_exists('type', $fields)) {
            return ItemValidationResult::rejected(ItemRejectionReason::TypeMissing);
        }

        $type = $fields['type'];

        // JSON integer のみ許可する。"1326" (string) や 1326.0 (float) は採用しない。
        // PHP の bool は is_int() が false なので true/false もここで落ちる。
        if (! is_int($type)) {
            return ItemValidationResult::rejected(
                ItemRejectionReason::TypeNotInteger,
                'type is '.get_debug_type($type),
            );
        }

        $catalogEntry = $catalog->get($type);
        if ($catalogEntry === null) {
            return ItemValidationResult::rejected(
                ItemRejectionReason::TypeNotInCatalog,
                sprintf('type %d is not in the Terraria %s catalog', $type, $catalog->terrariaVersion),
                $type,
            );
        }

        if (! array_key_exists('stack', $fields)) {
            return ItemValidationResult::rejected(ItemRejectionReason::StackMissing, null, $type);
        }

        $stack = $fields['stack'];

        if (! is_int($stack)) {
            return ItemValidationResult::rejected(
                ItemRejectionReason::StackNotInteger,
                'stack is '.get_debug_type($stack),
                $type,
            );
        }

        if ($stack < 1 || $stack > $catalogEntry->maxStack) {
            return ItemValidationResult::rejected(
                ItemRejectionReason::StackOutOfRange,
                sprintf('stack %d is outside 1..%d', $stack, $catalogEntry->maxStack),
                $type,
            );
        }

        return ItemValidationResult::valid($type, $stack, $catalogEntry);
    }

    /**
     * JSON object 相当なら field の連想配列を返し、そうでなければ null を返す。
     *
     * `json_decode($json, true)` 済みの配列と `stdClass` の両方を受け付ける。
     * JSON array (`[1, 2]`) は object ではないので拒否する。
     *
     * @return array<string, mixed>|null
     */
    private static function asObjectFields(mixed $entry): ?array
    {
        if ($entry instanceof stdClass) {
            return get_object_vars($entry);
        }

        if (! is_array($entry)) {
            return null;
        }

        // 空の JSON object `{}` は assoc decode で `[]` になる。list かどうかで
        // 区別できないため空配列は object 扱いとし、field 不足として落とす。
        if ($entry !== [] && array_is_list($entry)) {
            return null;
        }

        /** @var array<string, mixed> $entry */
        return $entry;
    }
}
