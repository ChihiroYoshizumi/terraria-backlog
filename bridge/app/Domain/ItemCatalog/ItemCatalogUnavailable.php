<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

use RuntimeException;

/**
 * 採用 Terraria version の Item catalog を読めなかったことを表す設定エラー。
 *
 * docs/design.md §6.4 の Item 単位 validation は catalog が読めている前提で成立する。
 * catalog 不在・破損・version 不一致は「Item を skip する」のではなく fail closed にする。
 * 別 version の catalog で代用しない。
 */
final class ItemCatalogUnavailable extends RuntimeException
{
    public static function fileMissing(string $terrariaVersion, string $path): self
    {
        return new self(sprintf(
            'Item catalog for Terraria %s is not readable at "%s". docs/design.md §2.3 / §6.4',
            $terrariaVersion,
            $path,
        ));
    }

    public static function unreadable(string $terrariaVersion, string $path, string $reason): self
    {
        return new self(sprintf(
            'Item catalog for Terraria %s at "%s" is unusable: %s',
            $terrariaVersion,
            $path,
            $reason,
        ));
    }

    public static function versionMismatch(string $requested, string $found, string $path): self
    {
        return new self(sprintf(
            'Item catalog at "%s" declares terrariaVersion "%s" but "%s" was requested. '
            .'Catalogs are version-pinned and must not be reused across versions (docs/design.md §6.4).',
            $path,
            $found,
            $requested,
        ));
    }

    public static function invalidVersionString(string $terrariaVersion): self
    {
        return new self(sprintf(
            'Terraria version "%s" is not a valid catalog directory name.',
            $terrariaVersion,
        ));
    }

    public static function duplicateItemId(string $terrariaVersion, int $itemId): self
    {
        return new self(sprintf(
            'Item catalog for Terraria %s contains duplicate item id %d.',
            $terrariaVersion,
            $itemId,
        ));
    }
}
