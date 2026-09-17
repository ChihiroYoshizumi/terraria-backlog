<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Diagnostics;

/**
 * `terraria:doctor` の検証項目1件 (docs/design.md §9.4)。
 *
 * `skipped` は「前提が崩れて検証できなかった」状態であり、fail closed のため
 * OK として扱わない (docs/design.md §18.4)。
 */
final readonly class ConfigurationCheck
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    private function __construct(
        public string $name,
        public string $status,
        public string $detail,
    ) {}

    public static function ok(string $name, string $detail = ''): self
    {
        return new self($name, self::STATUS_OK, $detail);
    }

    public static function failed(string $name, string $detail): self
    {
        return new self($name, self::STATUS_FAILED, $detail);
    }

    public static function skipped(string $name, string $detail): self
    {
        return new self($name, self::STATUS_SKIPPED, $detail);
    }

    public function isSatisfied(): bool
    {
        return $this->status === self::STATUS_OK;
    }
}
