<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Achievement;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\WorldFlagEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/design.md §8.1 / §8.2、docs/specs/world-progress.md、AC-08 / AC-17。
 */
final class WorldFlagEvaluatorTest extends TestCase
{
    /**
     * docs/design.md §8.1 の対応表そのもの。表を書き換えたらこのテストが落ちる。
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function supportedFlagTable(): array
    {
        return [
            ['downedBoss1', 'boss:eye_of_cthulhu'],
            ['downedBoss3', 'boss:skeletron'],
            ['hardMode', 'world:hardmode'],
            ['downedMechBoss1', 'boss:the_destroyer'],
            ['downedMechBoss2', 'boss:the_twins'],
            ['downedMechBoss3', 'boss:skeletron_prime'],
            ['downedPlantBoss', 'boss:plantera'],
            ['downedGolemBoss', 'boss:golem'],
            ['downedAncientCultist', 'boss:lunatic_cultist'],
            ['downedMoonlord', 'boss:moon_lord'],
        ];
    }

    /**
     * `supportedFlagTable()` の flag 名だけを渡す provider。
     * 対応表を正本に保ったまま、引数1つのテストへ渡すために派生させる
     * （provider が余分な引数を渡すと PHPUnit warning になり exit code が 1 になる）。
     *
     * @return list<array{0: string}>
     */
    public static function supportedFlagNames(): array
    {
        return array_map(
            static fn (array $row): array => [$row[0]],
            self::supportedFlagTable(),
        );
    }

    #[DataProvider('supportedFlagTable')]
    public function test_each_supported_flag_maps_to_its_achievement_key(string $flag, string $expectedKey): void
    {
        $achievements = (new WorldFlagEvaluator)->evaluate([$flag => true]);

        $this->assertSame([$expectedKey], Achievement::keyStrings($achievements));
        $this->assertSame($flag, $achievements[0]->metadata['flag']);
    }

    public function test_all_flags_true_yields_exactly_the_table(): void
    {
        $flags = [];
        $expected = [];
        foreach (self::supportedFlagTable() as [$flag, $key]) {
            $flags[$flag] = true;
            $expected[] = $key;
        }

        $achievements = (new WorldFlagEvaluator)->evaluate($flags);

        $this->assertSame($expected, Achievement::keyStrings($achievements));
        $this->assertSame($expected, array_unique($expected));
    }

    #[DataProvider('supportedFlagNames')]
    public function test_false_flag_yields_no_achievement(string $flag): void
    {
        $this->assertSame([], (new WorldFlagEvaluator)->evaluate([$flag => false]));
    }

    public function test_missing_flags_yield_no_achievement(): void
    {
        $this->assertSame([], (new WorldFlagEvaluator)->evaluate([]));
    }

    /**
     * 型 coercion はしない。JSON boolean の true 以外は根拠にしない。
     *
     * @return list<array{0: mixed}>
     */
    public static function truthyButNotTrue(): array
    {
        return [[1], ['true'], ['1'], [1.0], [[]], [null], ['yes']];
    }

    #[DataProvider('truthyButNotTrue')]
    public function test_non_boolean_flag_values_are_not_treated_as_downed(mixed $value): void
    {
        $this->assertSame([], (new WorldFlagEvaluator)->evaluate(['downedBoss1' => $value]));
    }

    /**
     * docs/design.md §8.2: `downedBoss2` は Eater of Worlds と Brain of Cthulhu を
     * 区別しない共有フラグなので、どちらの個別撃破も推測しない。
     */
    public function test_downed_boss2_never_produces_an_individual_boss_achievement(): void
    {
        $achievements = (new WorldFlagEvaluator)->evaluate([
            'downedBoss2' => true,
            'downedBoss1' => true,
        ]);

        $keys = Achievement::keyStrings($achievements);

        $this->assertSame(['boss:eye_of_cthulhu'], $keys, 'downedBoss2 must not add any achievement');
        $this->assertNotContains('boss:eater_of_worlds', $keys);
        $this->assertNotContains('boss:brain_of_cthulhu', $keys);
        $this->assertNotContains('boss:downed_boss2', $keys);
        $this->assertNotContains('downedBoss2', WorldFlagEvaluator::supportedFlags());
    }

    /**
     * docs/design.md §8.2 / AC-17: hardMode は `world:hardmode` だけを意味する。
     * Wall of Flesh 撃破を推測して登録しない。
     */
    public function test_hard_mode_never_produces_wall_of_flesh(): void
    {
        $achievements = (new WorldFlagEvaluator)->evaluate(['hardMode' => true]);

        $keys = Achievement::keyStrings($achievements);

        $this->assertSame(['world:hardmode'], $keys);
        $this->assertNotContains('boss:wall_of_flesh', $keys);
    }

    public function test_unknown_flags_are_ignored(): void
    {
        $achievements = (new WorldFlagEvaluator)->evaluate([
            'downedQueenBee' => true,
            'downedFishron' => true,
            'downedSlimeKing' => true,
            'downedPirates' => true,
            'downedGoblins' => true,
            'hardMode' => true,
        ]);

        $this->assertSame(['world:hardmode'], Achievement::keyStrings($achievements));
    }

    public function test_supported_flags_match_the_design_table(): void
    {
        $expected = array_column(self::supportedFlagTable(), 0);

        $this->assertSame($expected, WorldFlagEvaluator::supportedFlags());
    }
}
