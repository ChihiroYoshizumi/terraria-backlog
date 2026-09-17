<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Snapshot\WorldKey;

/**
 * 攻略課題 Mapping の読み取り境界 (docs/design.md §19.1)。
 *
 * Domain / Application 層は Mapping の実体が Backlog Issue であることに依存しない。
 * MVP の実装は Backlog のみ (`BacklogMappingRepository`)。
 */
interface MappingRepository
{
    /**
     * 対象 Project / World の **未完了** 攻略課題を一度だけ全ページ取得し index 化する
     * (docs/design.md §11, §20)。
     *
     * Snapshot / Reconciliation ごとに 1 回だけ呼ぶこと。Achievement ごとに呼ぶと
     * full scan を繰り返してしまう。
     *
     * 後付け Mapping (post-hoc Mapping) と reopen はどちらも「呼び直せば最新の
     * 未完了 Mapping が取れる」ことで成立する。index を跨いでキャッシュしない
     * (AC-06, AC-15, docs/specs/backlog-mapping.md)。
     *
     * 検索障害は「該当 0 件」と扱わず例外として伝播する。実装依存の例外型
     * (Backlog 実装なら `BacklogApiException`) を Domain 側で型として固定しない。
     */
    public function loadIncompleteIndex(WorldKey $world): MappingIndex;

    /**
     * 攻略課題を configured Done Status へ更新する (docs/design.md §12, §21)。
     *
     * PATCH の前に必ず current issue を再取得し、Project ID / 未完了 /
     * Record Type が null か空 / World Key / Terraria Key をすべて再確認する。
     * 1 つでも崩れていたら更新しない。update failure を success 扱いしない。
     */
    public function complete(MappingIssue $mapping): CompletionResult;
}
