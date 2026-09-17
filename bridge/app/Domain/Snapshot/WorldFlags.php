<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * World 進捗フラグ (docs/design.md §6.2, §8.1)。
 *
 * キー名は対応 Terraria version (docs/design.md §2.3) の
 * `Terraria.NPC` / `Terraria.Main` のフィールド名に対応する。
 * 値は JSON boolean のみを受理する (型 coercion は行わない)。
 */
final readonly class WorldFlags
{
    /**
     * @param  array<string, bool>  $flags
     */
    public function __construct(private array $flags) {}

    /**
     * フラグが存在し、かつ true のときだけ true を返す。
     * 未知・未送信のフラグを「達成」と推測しない (AC-17)。
     */
    public function isSet(string $name): bool
    {
        return ($this->flags[$name] ?? false) === true;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->flags);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return $this->flags;
    }
}
