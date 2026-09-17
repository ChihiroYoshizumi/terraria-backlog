<?php

declare(strict_types=1);

namespace App\Domain\Achievement;

/**
 * Achievement Key の prefix (docs/spec.md §5.1)。
 *
 * MVP で PHP が生成するのは Boss / World / Item の 3 種類。
 * `event:` は spec 上の予約だが、再判定可能な永続フラグを確認できていないため
 * Task 04 の evaluator は生成しない (docs/design.md §8.2)。
 */
enum AchievementPrefix: string
{
    case Boss = 'boss';
    case World = 'world';
    case Item = 'item';
}
