<?php

declare(strict_types=1);

namespace App\Domain\Registry;

/**
 * `failed` の機械可読な理由 (docs/design.md §17, §18)。
 *
 * 運用者が「重複で止めたのか」「Backlog 障害なのか」を切り分けられるようにする。
 * Task 07 はこの値で通知文言を切り替えてよいが、いずれも成功 ACK にはしない。
 */
enum RegistryFailureReason: string
{
    /** exact Registry が複数あり、完了済みが 0 件 (docs/design.md §10.4 fail closed)。 */
    case DuplicateIncomplete = 'registry.duplicate_incomplete';

    /** Registry scan / 限定再検索が失敗した。0 件と読み替えない (docs/design.md §20)。 */
    case LookupFailed = 'registry.lookup_failed';

    /** create / update の応答が不明。再検索でも確定できなかった (docs/spec.md §9)。 */
    case WriteResultUnknown = 'registry.write_result_unknown';

    /** create / update が明確に失敗した。 */
    case WriteFailed = 'registry.write_failed';

    /** 書き込み後の保存確認で完了済み Registry を確認できなかった。 */
    case VerificationFailed = 'registry.verification_failed';

    /** cross-process lock を取得できなかった。lock 無しでは書き込まない。 */
    case LockUnavailable = 'registry.lock_unavailable';
}
