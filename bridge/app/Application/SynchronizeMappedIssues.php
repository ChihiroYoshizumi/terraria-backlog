<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Achievement\AchievementKey;
use App\Domain\Mapping\MappingIndex;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * 完了済み Registry と未完了 Mapping を突合し、対応する攻略課題を完了させる
 * (docs/design.md §12 手順 5, 8, 9 / AC-06, AC-07, AC-10, AC-14, AC-15, AC-19)。
 *
 * 重要な設計上の制約:
 *
 * - **full Mapping scan は 1 回だけ**。Achievement ごとに scan を繰り返さない
 *   (docs/design.md §12, §20)。index を渡されればそれを使い、無ければここで
 *   1 回だけ読む。
 * - join はメモリ上で行う。Registry 未達成の Achievement に紐付く課題は
 *   候補にすらならない (AC-07)。
 * - 部分失敗を成功に丸めない。失敗は結果に残し、次回 reconciliation で再試行する
 *   (AC-10)。永続 retry queue は持たない (docs/spec.md §9)。
 * - scan 失敗 (Backlog 障害) は「0 件」と読み替えず例外として伝播させる。
 *
 * reconciliation の契機 (periodic / manual) は Task 07 の責務。ここは
 * 「呼ばれたらその時点の Backlog の状態で 1 回照合する」ことだけを担う。
 */
final readonly class SynchronizeMappedIssues
{
    public function __construct(
        private MappingRepository $mappings,
        private LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * @param  list<AchievementKey|string>  $completedAchievementKeys  完了済み Registry set
     * @param  MappingIndex|null  $index  Snapshot 処理で既に読み込んだ index (docs/design.md §12 手順 5)
     */
    public function synchronize(
        WorldKey $world,
        array $completedAchievementKeys,
        ?MappingIndex $index = null,
    ): SynchronizeMappedIssuesResult {
        // index を渡されない場合だけ読む。Snapshot / Reconciliation 1 回につき
        // full scan は 1 回。
        $index ??= $this->mappings->loadIncompleteIndex($world);

        if ($index->world->value !== $world->value) {
            // World を跨いだ index の取り違えは「他ワールドの達成で完了させる」
            // 事故 (AC-13) に直結するため即座に落とす。
            throw new InvalidArgumentException('mapping index が対象 World と一致しない.');
        }

        $results = [];

        foreach ($index->forCompletedAchievements($completedAchievementKeys) as $mapping) {
            // complete() の中で PATCH 前に current issue を再取得して再確認する。
            $results[] = $this->mappings->complete($mapping);
        }

        $result = new SynchronizeMappedIssuesResult(
            worldKey: $world->value,
            results: $results,
            scannedMappings: $index->count(),
            completedAchievements: count($this->normalize($completedAchievementKeys)),
        );

        $this->logger->info('mapping.sync_completed', [
            'operation' => 'mapping.sync',
        ] + $result->toLogContext());

        return $result;
    }

    /**
     * Registry index をそのまま渡すための糖衣 (docs/design.md §12 手順 8)。
     *
     * 「完了済み Registry set」の定義を Task 07 側で再実装させない。
     */
    public function synchronizeWithRegistry(
        WorldKey $world,
        RegistryIndex $registry,
        ?MappingIndex $index = null,
    ): SynchronizeMappedIssuesResult {
        if ($registry->world->value !== $world->value) {
            throw new InvalidArgumentException('registry index が対象 World と一致しない.');
        }

        return $this->synchronize($world, $registry->completedAchievementKeys(), $index);
    }

    /**
     * @param  list<AchievementKey|string>  $keys
     * @return list<string>
     */
    private function normalize(array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[$key instanceof AchievementKey ? $key->toString() : $key] = true;
        }

        return array_map(strval(...), array_keys($normalized));
    }
}
