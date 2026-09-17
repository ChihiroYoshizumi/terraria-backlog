<?php

declare(strict_types=1);

namespace App\Domain\Achievement;

use InvalidArgumentException;

/**
 * Achievement Key として成立しない文字列を組み立てようとしたときに投げる。
 *
 * これはプログラミングエラー扱いであり、Snapshot の Item 単位 validation
 * (不正な Item だけ無視して評価を継続する / docs/design.md §6.4) とは区別する。
 */
final class InvalidAchievementKey extends InvalidArgumentException
{
    public static function forValue(string $value, string $reason): self
    {
        return new self(sprintf('Invalid achievement key "%s": %s', $value, $reason));
    }
}
