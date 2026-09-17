<?php

declare(strict_types=1);

namespace App\Domain\Registry;

/**
 * `ensureRegistered` の結果 (docs/design.md §10.4)。
 *
 * Task 07 はこの値で ACK / 通知の可否を決める。`Failed` で成功 ACK を出してはならない
 * (docs/spec.md §6, §9, AC-03 / AC-09 / AC-12)。
 */
enum RegistryStatus: string
{
    /** 本 Snapshot で作成または修復し、保存を確認できた。 */
    case Registered = 'registered';

    /** 既に完了済み Registry が存在した。書き込みは行っていない。 */
    case AlreadyRegistered = 'already_registered';

    /** 保存を確認できなかった / fail closed。成功 ACK を出さない。 */
    case Failed = 'failed';
}
