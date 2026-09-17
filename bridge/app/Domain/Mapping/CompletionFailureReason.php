<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * `Skipped` / `Failed` の機械可読な理由 (docs/design.md §17, §18)。
 *
 * 運用者が「安全に見送ったのか」「Backlog 障害なのか」を切り分けられるようにする。
 */
enum CompletionFailureReason: string
{
    /** PATCH 前の再取得 (GET) が失敗した。状態不明のまま更新しない。 */
    case LookupFailed = 'mapping.lookup_failed';

    /** 再取得したら Mapping 属性が解除・変更されていた (AC-15)。 */
    case MappingChanged = 'mapping.mapping_changed';

    /** 再取得したら Project / World / Terraria Key が対象と一致しなかった。 */
    case MappingMismatch = 'mapping.mapping_mismatch';

    /** PATCH が明確に失敗した (4xx 等)。success 扱いしない。 */
    case WriteFailed = 'mapping.write_failed';

    /** PATCH の結果が不明 (timeout / 5xx / 429)。再取得でも完了を確認できなかった。 */
    case WriteResultUnknown = 'mapping.write_result_unknown';

    /** PATCH 後に完了状態を確認できなかった。 */
    case VerificationFailed = 'mapping.verification_failed';
}
