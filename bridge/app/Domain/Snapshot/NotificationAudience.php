<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * 通知の宛先 (docs/design.md §6.5, §15.2)。
 *
 * `server` は server console のみを意味し、ゲーム内の全体チャットには表示しない。
 */
enum NotificationAudience: string
{
    case Players = 'players';
    case Server = 'server';
}
