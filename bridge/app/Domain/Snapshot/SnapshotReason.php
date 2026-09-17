<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Snapshot の観測契機 (docs/design.md §6.3)。
 *
 * 達成判定は reason に依存させない。診断・ACK 表示・観測契機の把握にのみ使う。
 */
enum SnapshotReason: string
{
    case Startup = 'startup';
    case Periodic = 'periodic';
    case CollectionChange = 'collection_change';
    case WorldChange = 'world_change';
    case Manual = 'manual';
}
