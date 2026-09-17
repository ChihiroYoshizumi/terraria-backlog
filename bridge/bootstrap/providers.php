<?php

use App\Providers\AchievementServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BacklogServiceProvider;
use App\Providers\DebugSnapshotLoggingServiceProvider;
use App\Providers\SnapshotServiceProvider;

return [
    AppServiceProvider::class,
    AchievementServiceProvider::class,
    BacklogServiceProvider::class,
    SnapshotServiceProvider::class,
    // Task 08 実機確認用の診断ログ。TERRARIA_DEBUG_LOG_ACHIEVEMENTS=true のときだけ有効。
    // Task 07 実装時に削除する。
    DebugSnapshotLoggingServiceProvider::class,
];
