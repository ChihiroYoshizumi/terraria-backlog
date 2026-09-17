<?php

declare(strict_types=1);

namespace App\Domain\Achievement;

/**
 * 永続的な World flag から Achievement を評価する (docs/design.md §8.1)。
 *
 * ここが「どの raw state をどの Achievement とみなすか」の唯一の実装であり、
 * C# Adapter 側には同等の判定を置かない (docs/specs/world-progress.md)。
 */
final class WorldFlagEvaluator
{
    /**
     * docs/design.md §8.1 の対応表。キーは Terraria 1.3.0.8 の永続フラグ名、
     * 値は Achievement Key の (prefix, suffix)。
     *
     * この表に無いフラグからは Achievement を生成しない。特に:
     *
     * - `downedBoss2` は Eater of Worlds と Brain of Cthulhu を区別しないため、
     *   どちらの個別撃破も推測しない (docs/design.md §8.2)。
     * - `hardMode` は `world:hardmode` だけを意味する。Wall of Flesh 撃破を
     *   独立して証明する永続 state ではないため `boss:wall_of_flesh` を生成しない
     *   (docs/design.md §8.2 / docs/spec.md AC-17)。
     *
     * @var array<string, array{0: AchievementPrefix, 1: string}>
     */
    private const array FLAG_TABLE = [
        'downedBoss1' => [AchievementPrefix::Boss, 'eye_of_cthulhu'],
        'downedBoss3' => [AchievementPrefix::Boss, 'skeletron'],
        'hardMode' => [AchievementPrefix::World, 'hardmode'],
        'downedMechBoss1' => [AchievementPrefix::Boss, 'the_destroyer'],
        'downedMechBoss2' => [AchievementPrefix::Boss, 'the_twins'],
        'downedMechBoss3' => [AchievementPrefix::Boss, 'skeletron_prime'],
        'downedPlantBoss' => [AchievementPrefix::Boss, 'plantera'],
        'downedGolemBoss' => [AchievementPrefix::Boss, 'golem'],
        'downedAncientCultist' => [AchievementPrefix::Boss, 'lunatic_cultist'],
        'downedMoonlord' => [AchievementPrefix::Boss, 'moon_lord'],
    ];

    /**
     * 対応表に載っているフラグ名。
     *
     * @return list<string>
     */
    public static function supportedFlags(): array
    {
        return array_keys(self::FLAG_TABLE);
    }

    /**
     * `true` (JSON boolean) のフラグだけを Achievement にする。
     * `false` / 未送信 / 表外のフラグからは何も生成しない。
     *
     * 出力順は対応表の順で決定的にする。
     *
     * @param  array<array-key, mixed>  $flags
     * @return list<Achievement>
     */
    public function evaluate(array $flags): array
    {
        $achievements = [];

        foreach (self::FLAG_TABLE as $flag => [$prefix, $suffix]) {
            if (($flags[$flag] ?? null) !== true) {
                continue;
            }

            $key = $prefix === AchievementPrefix::Boss
                ? AchievementKey::boss($suffix)
                : AchievementKey::world($suffix);

            $achievements[] = new Achievement($key, ['flag' => $flag]);
        }

        return $achievements;
    }
}
