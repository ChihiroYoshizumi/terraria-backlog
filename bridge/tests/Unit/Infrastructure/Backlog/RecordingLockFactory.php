<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Lock\CrossProcessLock;
use App\Infrastructure\Lock\CrossProcessLockFactory;
use App\Infrastructure\Lock\LockUnavailableException;

/**
 * lock の取得・解放と、その時点までに送られたリクエスト数を記録する spy。
 *
 * 「書き込みが lock の内側で起きているか」を検証するために使う。
 * 実際の cross-process 排他そのものは `FileLockTest` が別プロセスで検証する。
 */
final class RecordingLockFactory implements CrossProcessLockFactory
{
    /** @var list<array{worldKey: string, achievementKey: string}> */
    public array $acquired = [];

    /** @var list<string> */
    public array $released = [];

    /** @var list<int> lock 取得時点で既に送信済みだったリクエスト数 */
    public array $requestsWhenAcquired = [];

    public bool $unavailable = false;

    public function __construct(private readonly ?FakeBacklog $backlog = null) {}

    public function forAchievement(string $worldKey, string $achievementKey): CrossProcessLock
    {
        if ($this->unavailable) {
            throw new LockUnavailableException('test: lock unavailable');
        }

        $this->acquired[] = ['worldKey' => $worldKey, 'achievementKey' => $achievementKey];
        $this->requestsWhenAcquired[] = $this->backlog === null ? 0 : count($this->backlog->requests);

        return new RecordingLock($this, $worldKey.'|'.$achievementKey);
    }

    public function markReleased(string $name): void
    {
        $this->released[] = $name;
    }
}
