<?php

declare(strict_types=1);

namespace App\Domain\Registry;

use App\Domain\Achievement\Achievement;
use App\Domain\Snapshot\WorldKey;

/**
 * Achievement Registry の永続化境界 (docs/design.md §19.1)。
 *
 * Domain / Application 層は Registry の実体が Backlog Issue であることに依存しない。
 * MVP の実装は Backlog のみ (`BacklogRegistryRepository`)。
 */
interface RegistryRepository
{
    /**
     * 対象 Project / World の Registry を一度だけ全ページ取得し index 化する
     * (docs/design.md §10.3, §20)。
     *
     * Snapshot ごとに 1 回だけ呼ぶこと。Achievement ごとに呼ぶと全件 scan を
     * 繰り返してしまう。
     *
     * 検索障害は「該当 0 件」と扱わず例外として伝播する。実装依存の例外型
     * (Backlog 実装なら `BacklogApiException`) を Domain 側で型として固定しない。
     */
    public function loadIndex(WorldKey $world): RegistryIndex;

    /**
     * Registry の存在を保証する (docs/design.md §10.4)。
     *
     * 書き込みが必要な場合のみ `world_key + achievement_key` の cross-process lock を
     * 取得し、「最新 exact Registry 確認 -> create/update -> 保存確認」までを lock 内で行う。
     *
     * 保存を確認できるまで success を返さない。成功時は `$index` も更新する。
     */
    public function ensureRegistered(
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult;
}
