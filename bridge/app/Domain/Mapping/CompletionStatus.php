<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * 攻略課題 1 件の完了同期結果 (docs/design.md §12, §21)。
 *
 * `Completed` 以外を「完了できた」と読み替えない。特に `Failed` は
 * update failure であり success 扱いしない (docs/specs/backlog-sync.md)。
 */
enum CompletionStatus: string
{
    /** 本同期で Done Status へ更新し、完了を確認できた。 */
    case Completed = 'completed';

    /** PATCH 直前の再取得時点で既に完了していた。更新も コメントも行わない (AC-11)。 */
    case AlreadyCompleted = 'already_completed';

    /**
     * 再取得の結果 Mapping が解除・変更されていた / 対象外だった。
     * 意図的に更新しない正常系であり失敗ではない (AC-15)。
     */
    case Skipped = 'skipped';

    /** 更新または確認に失敗した。次回 reconciliation で再試行する (AC-10)。 */
    case Failed = 'failed';
}
