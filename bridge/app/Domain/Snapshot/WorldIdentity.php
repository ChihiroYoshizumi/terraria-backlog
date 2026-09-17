<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Snapshot が対象とする World (docs/design.md §5.1, §6.2)。
 *
 * `name` は観測情報であり識別には使わない (docs/specs/world-identity.md)。
 */
final readonly class WorldIdentity
{
    public function __construct(
        public WorldKey $key,
        public int $terrariaWorldId,
        public ?string $name = null,
    ) {}
}
