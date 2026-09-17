<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Achievement;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementKey;
use App\Domain\Achievement\AchievementPrefix;
use App\Domain\Achievement\InvalidAchievementKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/spec.md §5.1: Achievement Key は `boss:` / `world:` / `item:` を扱う。
 */
final class AchievementKeyTest extends TestCase
{
    public function test_boss_and_world_keys_render_with_their_prefix(): void
    {
        $this->assertSame('boss:eye_of_cthulhu', AchievementKey::boss('eye_of_cthulhu')->toString());
        $this->assertSame('world:hardmode', AchievementKey::world('hardmode')->toString());
    }

    public function test_item_key_uses_the_native_item_type(): void
    {
        $key = AchievementKey::item(1326);

        $this->assertSame(AchievementPrefix::Item, $key->prefix);
        $this->assertSame('item:1326', $key->toString());
        $this->assertSame('item:1326', (string) $key);
    }

    public function test_from_string_round_trips_every_supported_prefix(): void
    {
        foreach (['boss:moon_lord', 'world:hardmode', 'item:0', 'item:3601'] as $value) {
            $this->assertSame($value, AchievementKey::fromString($value)->toString());
        }
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidKeys(): array
    {
        return [
            ['moon_lord'],
            ['npc:moon_lord'],
            ['boss:Moon_Lord'],
            ['boss:moon lord'],
            ['boss:'],
            ['item:abc'],
            ['item:1.5'],
            ['item:-1'],
            ['item:01'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_from_string_rejects_malformed_keys(string $value): void
    {
        $this->expectException(InvalidAchievementKey::class);

        AchievementKey::fromString($value);
    }

    public function test_negative_item_type_is_rejected(): void
    {
        $this->expectException(InvalidAchievementKey::class);

        AchievementKey::item(-1);
    }

    public function test_equality_is_by_prefix_and_suffix(): void
    {
        $this->assertTrue(AchievementKey::item(2)->equals(AchievementKey::fromString('item:2')));
        $this->assertFalse(AchievementKey::item(2)->equals(AchievementKey::item(3)));
        $this->assertFalse(AchievementKey::boss('golem')->equals(AchievementKey::world('golem')));
    }

    public function test_achievement_exposes_key_string_and_diagnostic_metadata(): void
    {
        $achievement = new Achievement(AchievementKey::item(1326), ['itemName' => 'Rod of Discord']);

        $this->assertSame('item:1326', $achievement->keyString());
        $this->assertSame(['itemName' => 'Rod of Discord'], $achievement->metadata);
        $this->assertSame(['item:1326'], Achievement::keyStrings([$achievement]));
    }
}
