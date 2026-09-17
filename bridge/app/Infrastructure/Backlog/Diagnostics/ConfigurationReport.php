<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Diagnostics;

/**
 * 検証結果の集合 (docs/design.md §9.4, §18.4)。
 *
 * critical NG が1つでもあれば同期開始可能な状態として扱わない。
 * MVP では全項目を critical とする。誤った Project / Field へ書き込むと
 * 復旧できないため、警告止まりの項目を作らない。
 */
final readonly class ConfigurationReport
{
    /**
     * @param  list<ConfigurationCheck>  $checks
     */
    public function __construct(public array $checks = []) {}

    public function with(ConfigurationCheck ...$checks): self
    {
        return new self([...$this->checks, ...array_values($checks)]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->checks, ...$other->checks]);
    }

    public function isSatisfied(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return list<ConfigurationCheck>
     */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn (ConfigurationCheck $check): bool => ! $check->isSatisfied(),
        ));
    }
}
