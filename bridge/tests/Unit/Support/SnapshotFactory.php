<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Snapshot\CollectionChest;
use App\Domain\Snapshot\ItemRejectionReason;
use App\Domain\Snapshot\ObservedItem;
use App\Domain\Snapshot\RuntimeVersions;
use App\Domain\Snapshot\SnapshotReason;
use App\Domain\Snapshot\WorldFlags;
use App\Domain\Snapshot\WorldIdentity;
use App\Domain\Snapshot\WorldKey;
use App\Domain\Snapshot\WorldSnapshot;
use DateTimeImmutable;

/**
 * validated Snapshot DTO を組み立てるテスト用ビルダー。
 *
 * Task 02 の parser は通さない。Task 07 は「validated DTO を前提にする」
 * ため、ここでは request-level validation を再現しない。
 */
final class SnapshotFactory
{
    public const string WORLD_KEY = 'terraria:123456789';

    public const string CHEST_NAME = 'BACKLOG_COLLECTION';

    public const string TERRARIA_VERSION = '1.3.0.8';

    /**
     * @param  array<string, bool>  $flags
     * @param  list<array{type: mixed, stack: mixed, name?: string|null}>  $items
     * @param  list<string>  $playerNames
     */
    public static function make(
        SnapshotReason $reason = SnapshotReason::CollectionChange,
        array $items = [],
        array $flags = [],
        array $playerNames = ['player1'],
        bool $recoveryPending = false,
        string $requestId = '0199f136-9e36-7f41-b148-e5b4f384a321',
    ): WorldSnapshot {
        $observed = [];

        foreach ($items as $index => $item) {
            $rejections = [];

            if (! is_int($item['type'])) {
                $rejections[] = ItemRejectionReason::TypeNotInteger;
            }

            if (! is_int($item['stack'])) {
                $rejections[] = ItemRejectionReason::StackNotInteger;
            } elseif ($item['stack'] < 1) {
                $rejections[] = ItemRejectionReason::StackNotPositive;
            }

            $observed[] = new ObservedItem(
                index: $index,
                rawType: $item['type'],
                rawStack: $item['stack'],
                name: $item['name'] ?? null,
                rejections: $rejections,
            );
        }

        return new WorldSnapshot(
            schemaVersion: 1,
            requestId: $requestId,
            reason: $reason,
            observedAt: new DateTimeImmutable('2026-09-17T10:00:00+09:00'),
            recoveryPending: $recoveryPending,
            runtime: new RuntimeVersions('0.1.0', '4.3.13', self::TERRARIA_VERSION),
            world: new WorldIdentity(WorldKey::fromString(self::WORLD_KEY), 123456789, 'Fusic World'),
            collectionChestName: self::CHEST_NAME,
            flags: new WorldFlags($flags),
            collectionChests: [new CollectionChest(120, 340, self::CHEST_NAME, $observed)],
            triggerPlayerNames: $playerNames,
        );
    }
}
