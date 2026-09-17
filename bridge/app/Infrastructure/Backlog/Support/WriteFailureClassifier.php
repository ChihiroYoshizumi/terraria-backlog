<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Support;

use App\Infrastructure\Backlog\Exceptions\BacklogApiException;

/**
 * 書き込み例外を「確定的失敗」と「結果不明」へ振り分ける唯一の判定
 * (docs/design.md §13.4)。
 *
 * Registry (Task 05) と Mapping (Task 06) が独立に同じ判定を実装し、完全に同一の
 * private メソッドが 2 箇所へ複製されていた。書き込み経路が増えるたびに複製すると、
 * 片方だけ `! isRetriable()` に戻すような回帰を検出できない。判定はここ 1 箇所に置く。
 */
final class WriteFailureClassifier
{
    /**
     * 「書き込みが確定的に失敗した」と言い切れる例外か。
     *
     * §13.4 が「結果が不明」として次回検索での確認を求めるのは 5xx / Timeout
     * (と、同じく再評価対象の 429) に限られる。これらは `isRetriable() === true`。
     *
     * 一方 4xx (`BacklogRequestException` / `BacklogAuthenticationException`) は
     * Backlog が要求そのものを拒否しており、書き込みが起きていないことが確定する。
     * 再検索しても結論は変わらないため即 failed にしてよい。
     *
     * **`! isRetriable()` だけでは広すぎる。** HTTP status を持たない失敗
     * (transport 以外の想定外例外) や、2xx を受け取った後の payload 解釈失敗
     * (`backlog.unexpected_payload`) も `isRetriable() === false` だが、
     * これらは「書けたか不明」側であり再取得で確認しなければならない。
     * status の有無で区別し、不明側は保守的に再検索へ倒す。
     */
    public static function isDefinite(BacklogApiException $exception): bool
    {
        $status = $exception->status();

        return ! $exception->isRetriable() && $status !== null && $status >= 400;
    }
}
