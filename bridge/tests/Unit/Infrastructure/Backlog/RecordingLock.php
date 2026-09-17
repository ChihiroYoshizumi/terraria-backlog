<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Lock\CrossProcessLock;

final class RecordingLock implements CrossProcessLock
{
    private bool $released = false;

    public function __construct(
        private readonly RecordingLockFactory $factory,
        private readonly string $name,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->factory->markReleased($this->name);
    }

    public function name(): string
    {
        return $this->name;
    }
}
