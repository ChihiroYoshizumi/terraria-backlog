<?php

use App\Providers\AchievementServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BacklogServiceProvider;
use App\Providers\SnapshotServiceProvider;

return [
    AppServiceProvider::class,
    AchievementServiceProvider::class,
    BacklogServiceProvider::class,
    SnapshotServiceProvider::class,
];
