<?php

use App\Http\Controllers\Api\V1\SnapshotController;
use App\Http\Middleware\EnsureAdapterToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Adapter → PHP Bridge の endpoint (docs/design.md §6.1)。
| 外部から自由に達成を登録できる公開 endpoint は作らない (docs/spec.md §10)。
|
*/

Route::prefix('v1')->group(function (): void {
    // POST /api/v1/worlds/{worldKey}/snapshots
    //
    // worldKey は `terraria:<Main.worldID>` 形式か管理者設定の固定 ID
    // (docs/design.md §5.1)。path segment として安全な文字だけを受け付け、
    // 受理してよいワールドかは allowlist が判定する (§5.2)。
    Route::post('/worlds/{worldKey}/snapshots', [SnapshotController::class, 'store'])
        ->where('worldKey', '[A-Za-z0-9:._-]{1,128}')
        ->middleware(EnsureAdapterToken::class)
        ->name('api.v1.worlds.snapshots.store');
});
