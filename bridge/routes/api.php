<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Task 01 の時点では Snapshot endpoint (docs/design.md §6.1
| POST /api/v1/worlds/{worldKey}/snapshots) を実装しない。
| ここには後続 Task (02: snapshot-api-validation) が
| Domain/Application 層と接続したルートを追加する。
|
*/

Route::prefix('v1')->group(function (): void {
    // Task 02 以降でここに Snapshot endpoint 等を追加する。
});
