<?php

declare(strict_types=1);

namespace App\Application\Debug;

use App\Application\EvaluateAchievements;
use App\Application\EvaluateAchievementsInput;
use App\Domain\Achievement\Achievement;
use App\Domain\Snapshot\SnapshotProcessor;
use App\Domain\Snapshot\SnapshotResult;
use App\Domain\Snapshot\WorldSnapshot;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Task 08 の実機確認用の **診断専用** SnapshotProcessor。
 *
 * Task 04 の評価器を Snapshot へ繋いで「Bridge が何を受け取り、何を達成と判定したか」を
 * 構造化ログに出すだけで、Registry 保存 (Task 05) / Mapping 同期 (Task 06) /
 * 通知生成 (Task 07) は一切行わない。response は notification-only のまま空を返す。
 *
 * `TERRARIA_DEBUG_LOG_ACHIEVEMENTS=true` のときだけ bind される
 * (`App\Providers\DebugSnapshotLoggingServiceProvider`)。
 * Task 07 が `ProcessWorldSnapshot` を実装したらこのクラスと Provider は削除する。
 */
final readonly class LoggingSnapshotProcessor implements SnapshotProcessor
{
    public function __construct(
        private EvaluateAchievements $evaluate,
        private LoggerInterface $logger,
    ) {}

    public function process(WorldSnapshot $snapshot): SnapshotResult
    {
        $this->logger->info('debug.snapshot.received', [
            'reason' => $snapshot->reason->value,
            'worldKey' => $snapshot->worldKey()->value,
            'requestId' => $snapshot->requestId,
            'observedAt' => $snapshot->observedAt->format(DATE_ATOM),
            'recoveryPending' => $snapshot->recoveryPending,
            'playerNames' => $snapshot->triggerPlayerNames,
            'setFlags' => $this->setFlags($snapshot),
            'chests' => $this->chestSummary($snapshot),
        ]);

        try {
            $achievements = $this->evaluate->evaluate($this->toInput($snapshot));
        } catch (Throwable $exception) {
            // catalog 不在などで評価できない場合も Snapshot 処理は落とさない。
            $this->logger->warning('debug.achievement.evaluation_failed', [
                'requestId' => $snapshot->requestId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return SnapshotResult::withoutNotifications();
        }

        $this->logger->info('debug.achievements.evaluated', [
            'requestId' => $snapshot->requestId,
            'worldKey' => $snapshot->worldKey()->value,
            'total' => count($achievements),
            'boss' => $this->keysWithPrefix($achievements, 'boss:'),
            'world' => $this->keysWithPrefix($achievements, 'world:'),
            'items' => $this->itemSummary($achievements),
        ]);

        return SnapshotResult::withoutNotifications();
    }

    private function toInput(WorldSnapshot $snapshot): EvaluateAchievementsInput
    {
        $chests = [];

        foreach ($snapshot->collectionChests as $chest) {
            $items = [];

            // catalog 照合は Task 04 の責務なので、構造 reject 済みの Item も
            // 受信したままの値で渡して評価器自身に判定させる。
            foreach ($chest->items as $item) {
                $items[] = ['type' => $item->rawType, 'stack' => $item->rawStack];
            }

            $chests[] = [
                'x' => $chest->x,
                'y' => $chest->y,
                'name' => $chest->name,
                'items' => $items,
            ];
        }

        return new EvaluateAchievementsInput(
            worldKey: $snapshot->worldKey()->value,
            terrariaVersion: $snapshot->runtime->terrariaVersion,
            flags: $snapshot->flags->all(),
            collectionChests: $chests,
            requestId: $snapshot->requestId,
        );
    }

    /**
     * @return list<string>
     */
    private function setFlags(WorldSnapshot $snapshot): array
    {
        $set = [];

        foreach ($snapshot->flags->all() as $name => $value) {
            if ($value === true) {
                $set[] = (string) $name;
            }
        }

        return $set;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function chestSummary(WorldSnapshot $snapshot): array
    {
        return array_map(
            static fn ($chest): array => [
                'name' => $chest->name,
                'x' => $chest->x,
                'y' => $chest->y,
                'accepted' => count($chest->acceptedItems()),
                'rejected' => count($chest->rejectedItems()),
                'rawItems' => array_map(
                    static fn ($item): array => [
                        'type' => $item->rawType,
                        'stack' => $item->rawStack,
                        'name' => $item->name,
                    ],
                    $chest->items,
                ),
            ],
            $snapshot->collectionChests,
        );
    }

    /**
     * @param  list<Achievement>  $achievements
     * @return list<string>
     */
    private function keysWithPrefix(array $achievements, string $prefix): array
    {
        $keys = [];

        foreach ($achievements as $achievement) {
            $key = $achievement->keyString();

            if (str_starts_with($key, $prefix)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param  list<Achievement>  $achievements
     * @return list<array<string, mixed>>
     */
    private function itemSummary(array $achievements): array
    {
        $items = [];

        foreach ($achievements as $achievement) {
            if (! str_starts_with($achievement->keyString(), 'item:')) {
                continue;
            }

            $items[] = [
                'key' => $achievement->keyString(),
                'itemType' => $achievement->metadata['itemType'] ?? null,
                'itemName' => $achievement->metadata['itemName'] ?? null,
            ];
        }

        return $items;
    }
}
