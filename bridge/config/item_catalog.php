<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Item catalog base path
    |--------------------------------------------------------------------------
    |
    | version-pinned Item catalog は `<base_path>/<terrariaVersion>/items.json`
    | に置く (docs/design.md §6.4, contracts/items-v1.schema.json)。
    | 既定はリポジトリ同梱の contracts/terraria/。
    |
    | catalog が読めない場合は Snapshot を fail closed にする。runtime Terraria
    | version と異なる catalog へフォールバックはしない (docs/design.md §2.3)。
    |
    */

    'base_path' => env('TERRARIA_ITEM_CATALOG_PATH', dirname(base_path()).'/contracts/terraria'),

];
