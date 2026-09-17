<?php

declare(strict_types=1);

namespace App\Domain\Achievement;

/**
 * Achievement の論理識別子 (docs/spec.md §5.1)。
 *
 * string のまま持ち回すと prefix の表記ゆれや Adapter 由来文字列の混入を検出できないため
 * Value Object 化する。Adapter (C#) 側にはこの型を作らない (docs/design.md §8)。
 */
final readonly class AchievementKey
{
    /**
     * Boss / World の suffix。Key は表示名ではなく安定した slug とする。
     */
    private const string SLUG_PATTERN = '/\A[a-z0-9]+(?:_[a-z0-9]+)*\z/';

    /**
     * Item の suffix は Terraria native な Item.type (非負整数) をそのまま使う。
     */
    private const string ITEM_ID_PATTERN = '/\A(?:0|[1-9][0-9]*)\z/';

    private function __construct(
        public AchievementPrefix $prefix,
        public string $suffix,
    ) {}

    public static function boss(string $slug): self
    {
        return self::slugged(AchievementPrefix::Boss, $slug);
    }

    public static function world(string $slug): self
    {
        return self::slugged(AchievementPrefix::World, $slug);
    }

    /**
     * Item Achievement は `item:<item.type>` (docs/design.md §8.3)。
     * item name や chest 座標は一意性に使わない。
     */
    public static function item(int $itemType): self
    {
        if ($itemType < 0) {
            throw InvalidAchievementKey::forValue(
                AchievementPrefix::Item->value.':'.$itemType,
                'item type must not be negative',
            );
        }

        return new self(AchievementPrefix::Item, (string) $itemType);
    }

    public static function fromString(string $value): self
    {
        $parts = explode(':', $value, 2);

        if (count($parts) !== 2) {
            throw InvalidAchievementKey::forValue($value, 'expected "<prefix>:<suffix>"');
        }

        [$rawPrefix, $suffix] = $parts;

        $prefix = AchievementPrefix::tryFrom($rawPrefix);
        if ($prefix === null) {
            throw InvalidAchievementKey::forValue($value, sprintf('unknown prefix "%s"', $rawPrefix));
        }

        if ($prefix === AchievementPrefix::Item) {
            if (preg_match(self::ITEM_ID_PATTERN, $suffix) !== 1) {
                throw InvalidAchievementKey::forValue($value, 'item suffix must be a non-negative integer');
            }

            return new self($prefix, $suffix);
        }

        return self::slugged($prefix, $suffix);
    }

    public function toString(): string
    {
        return $this->prefix->value.':'.$this->suffix;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function equals(self $other): bool
    {
        return $this->prefix === $other->prefix && $this->suffix === $other->suffix;
    }

    private static function slugged(AchievementPrefix $prefix, string $slug): self
    {
        if (preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            throw InvalidAchievementKey::forValue(
                $prefix->value.':'.$slug,
                'suffix must be a lower snake_case slug',
            );
        }

        return new self($prefix, $slug);
    }
}
