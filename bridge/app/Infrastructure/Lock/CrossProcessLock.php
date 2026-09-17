<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

/**
 * 取得済みの排他 lock (docs/design.md §10.5)。
 *
 * 取得は Factory 側で完了しているため、本オブジェクトが存在することは
 * 「critical section の中にいる」ことを意味する。
 */
interface CrossProcessLock
{
    /**
     * lock を解放する。二重呼び出しは no-op とする。
     */
    public function release(): void;

    /**
     * 診断ログ用の lock 識別子 (秘密情報を含まない)。
     */
    public function name(): string;
}
