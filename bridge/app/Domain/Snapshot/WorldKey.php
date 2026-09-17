<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use InvalidArgumentException;

/**
 * World を識別する安定キー (docs/design.md §5.1, docs/specs/world-identity.md)。
 *
 * 既定形式は `terraria:<Main.worldID>` だが、`WorldKeyOverride` 運用があるため
 * 形式そのものは強制しない。「空でない・制御文字を含まない」ことだけを保証し、
 * 実際に受理してよいかは allowlist (docs/design.md §5.2) が決める。
 */
final readonly class WorldKey
{
    private function __construct(public string $value) {}

    public static function fromString(string $value): self
    {
        if ($value === '' || trim($value) !== $value) {
            throw new InvalidArgumentException('world key must be a non-empty trimmed string.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('world key must not contain control characters.');
        }

        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
