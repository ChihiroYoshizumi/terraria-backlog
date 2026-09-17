<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Snapshot に同梱される runtime version (docs/design.md §2.3, §6.2)。
 */
final readonly class RuntimeVersions
{
    public function __construct(
        public string $adapterVersion,
        public string $tshockVersion,
        public string $terrariaVersion,
    ) {}

    /**
     * `TERRARIA_SUPPORTED_RUNTIME` と同じ `<tshockVersion>:<terrariaVersion>` 形式。
     */
    public function pair(): string
    {
        return $this->tshockVersion.':'.$this->terrariaVersion;
    }

    public function matches(string $supportedPair): bool
    {
        return $supportedPair !== '' && hash_equals($supportedPair, $this->pair());
    }
}
