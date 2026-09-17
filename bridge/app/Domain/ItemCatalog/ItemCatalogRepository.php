<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

/**
 * version-pinned Item catalog の取得口 (docs/design.md §6.4)。
 */
interface ItemCatalogRepository
{
    /**
     * 指定した Terraria version 専用の catalog を返す。
     *
     * @throws ItemCatalogUnavailable その version の catalog を読めない場合 (fail closed)
     */
    public function forVersion(string $terrariaVersion): ItemCatalog;
}
