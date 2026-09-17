<?php

declare(strict_types=1);

namespace App\Domain\Achievement;

/**
 * 評価済みの Achievement 1 件。
 *
 * 同一性は `key` だけで決まる。`metadata` は診断用 (item name / 観測した chest 座標 /
 * 根拠になった world flag 名など) であり、Achievement の一意性には使わない
 * (docs/design.md §8.3)。
 */
final readonly class Achievement
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public AchievementKey $key,
        public array $metadata = [],
    ) {}

    public function keyString(): string
    {
        return $this->key->toString();
    }

    /**
     * @param  list<self>  $achievements
     * @return list<string>
     */
    public static function keyStrings(array $achievements): array
    {
        return array_values(array_map(
            static fn (self $achievement): string => $achievement->keyString(),
            $achievements,
        ));
    }
}
