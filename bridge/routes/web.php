<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Terraria Backlog Bridge は API 専用アプリとして運用する (bridge/README.md 参照)。
| ブラウザ向けの view は持たず、ルートはヘルスチェック用の JSON のみ返す。
| ヘルスチェック本体は `/up` (bootstrap/app.php withRouting health) を利用する。
|
*/

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
    ]);
});
