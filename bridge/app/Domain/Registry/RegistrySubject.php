<?php

declare(strict_types=1);

namespace App\Domain\Registry;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementPrefix;

/**
 * Registry 課題の件名 (docs/design.md §10.1, docs/spec.md §5.2)。
 *
 * ```text
 * [Terraria Registry] Rod of Discord
 * ```
 *
 * 件名は人間が識別するための表示であり、論理 Primary Key ではない。
 * 一意性・突合は常に Custom Field (`Terraria World Key` / `Terraria Key`) で行う。
 * 件名が変更されても Registry の同一性は失われない。
 */
final class RegistrySubject
{
    public const string PREFIX = '[Terraria Registry] ';

    /** Backlog Issue の件名上限に対する安全側の切り詰め長。 */
    private const int MAX_LENGTH = 255;

    private function __construct() {}

    public static function for(Achievement $achievement): string
    {
        return self::truncate(self::PREFIX.self::displayName($achievement));
    }

    /**
     * 人間向けの達成名。
     *
     * Item は catalog 由来の名前 (Task 04 が metadata に載せる) を使い、
     * 取得できない場合でも Item ID で識別できる文字列へ落とす。
     */
    public static function displayName(Achievement $achievement): string
    {
        $key = $achievement->key;

        if ($key->prefix === AchievementPrefix::Item) {
            $name = $achievement->metadata['itemName'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                return sprintf('%s (item %s)', trim($name), $key->suffix);
            }

            return sprintf('Item %s', $key->suffix);
        }

        return self::titleize($key->suffix);
    }

    /**
     * `eye_of_cthulhu` -> `Eye Of Cthulhu`。
     */
    private static function titleize(string $slug): string
    {
        return implode(' ', array_map(
            static fn (string $word): string => ucfirst($word),
            explode('_', $slug),
        ));
    }

    private static function truncate(string $subject): string
    {
        if (mb_strlen($subject) <= self::MAX_LENGTH) {
            return $subject;
        }

        return mb_substr($subject, 0, self::MAX_LENGTH - 1).'…';
    }
}
