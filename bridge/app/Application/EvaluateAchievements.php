<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementKey;
use App\Domain\Achievement\WorldFlagEvaluator;
use App\Domain\ItemCatalog\ItemCatalog;
use App\Domain\ItemCatalog\ItemCatalogRepository;
use App\Domain\ItemCatalog\ItemCatalogUnavailable;
use App\Domain\ItemCatalog\ItemEntryValidator;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * validated Snapshot から Achievement 候補を評価する (docs/design.md §8)。
 *
 * Achievement Key を生成するのはこの経路だけであり、C# Adapter 側には判定を置かない。
 *
 * - World flag は docs/design.md §8.1 の対応表だけを使う。
 * - Item は version-pinned catalog による Item 単位 validation を通ったものだけを使う。
 * - 不正 Item は `item.invalid_skipped` をログしてその Item だけ無視し、
 *   同 Snapshot の valid Item / Boss / World 評価は継続する (docs/design.md §6.4)。
 * - `reason` (`periodic` など) では判定を変えない。
 */
final readonly class EvaluateAchievements
{
    public function __construct(
        private ItemCatalogRepository $catalogs,
        private WorldFlagEvaluator $worldFlags,
        private ItemEntryValidator $itemValidator,
        private LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * @return list<Achievement> World flag 由来 → Item 由来 の順。Key は重複しない。
     *
     * @throws ItemCatalogUnavailable catalog を読めない場合 (fail closed)
     */
    public function evaluate(EvaluateAchievementsInput $input): array
    {
        // catalog 不在は設定エラーとして fail closed にする。Item を無条件に
        // 素通りさせたり、別 version の catalog で代用したりはしない。
        $catalog = $this->catalogs->forVersion($input->terrariaVersion);

        $achievements = $this->worldFlags->evaluate($input->flags);

        foreach ($this->evaluateItems($input, $catalog) as $itemAchievement) {
            $achievements[] = $itemAchievement;
        }

        return $achievements;
    }

    /**
     * @return list<Achievement>
     */
    private function evaluateItems(EvaluateAchievementsInput $input, ItemCatalog $catalog): array
    {
        /** @var array<int, Achievement> $byItemType */
        $byItemType = [];

        foreach ($input->collectionChests as $chestIndex => $chest) {
            $chestFields = is_array($chest) ? $chest : [];
            $items = $chestFields['items'] ?? null;

            if (! is_array($items)) {
                continue;
            }

            $chestX = is_int($chestFields['x'] ?? null) ? $chestFields['x'] : null;
            $chestY = is_int($chestFields['y'] ?? null) ? $chestFields['y'] : null;

            foreach ($items as $slot => $rawItem) {
                $result = $this->itemValidator->validate($rawItem, $catalog);

                if (! $result->valid) {
                    // docs/design.md §6.4: world / chest 座標・理由を診断ログに残し、
                    // この Item だけを無視して評価を続ける。
                    $this->logger->warning('item.invalid_skipped', [
                        'requestId' => $input->requestId,
                        'worldKey' => $input->worldKey,
                        'terrariaVersion' => $catalog->terrariaVersion,
                        'chestIndex' => is_int($chestIndex) ? $chestIndex : null,
                        'chestX' => $chestX,
                        'chestY' => $chestY,
                        'slot' => is_int($slot) ? $slot : null,
                        'itemType' => $result->itemType,
                        'reason' => $result->reason?->value,
                        'detail' => $result->detail,
                    ]);

                    continue;
                }

                /** @var int $itemType */
                $itemType = $result->itemType;

                // 同一 Item type が複数 slot / chest にあっても 1 Achievement に正規化する。
                // item name や chest 座標は診断 metadata であり一意性には使わない。
                if (isset($byItemType[$itemType])) {
                    continue;
                }

                $byItemType[$itemType] = new Achievement(
                    AchievementKey::item($itemType),
                    [
                        'itemType' => $itemType,
                        'itemName' => $result->entry?->name,
                        'firstSeenChestIndex' => is_int($chestIndex) ? $chestIndex : null,
                        'firstSeenChestX' => $chestX,
                        'firstSeenChestY' => $chestY,
                    ],
                );
            }
        }

        ksort($byItemType);

        return array_values($byItemType);
    }
}
