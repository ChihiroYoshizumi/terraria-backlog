<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotProcessor;
use App\Domain\Snapshot\SnapshotResult;
use App\Domain\Snapshot\WorldSnapshot;

/**
 * Application 層へ渡された validated Snapshot DTO を記録するテストダブル。
 *
 * 「request-level validation を通過した Snapshot が後続へ届いたか」と
 * 「Item 単位の不正がどう持ち越されたか」を検証するために使う。
 */
final class RecordingSnapshotProcessor implements SnapshotProcessor
{
    /** @var list<WorldSnapshot> */
    public array $received = [];

    /**
     * @param  list<SnapshotNotification>  $notifications
     */
    public function __construct(private readonly array $notifications = []) {}

    public function process(WorldSnapshot $snapshot): SnapshotResult
    {
        $this->received[] = $snapshot;

        return new SnapshotResult($this->notifications);
    }

    public function last(): WorldSnapshot
    {
        return $this->received[array_key_last($this->received)];
    }
}
