<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

use App\Infrastructure\Backlog\RateLimitStatus;

/**
 * 429 Too Many Requests (docs/design.md §13.3)。
 *
 * - 当該処理を failed/retriable とする。
 * - 成功 ACK を返さない。
 * - `X-RateLimit-Reset` を診断ログに残す。
 * - 次の periodic / manual reconciliation で再評価する。
 */
final class BacklogRateLimitException extends BacklogApiException
{
    public function __construct(string $message, private readonly RateLimitStatus $rateLimit)
    {
        parent::__construct($message, 429, 'backlog.rate_limited');
    }

    public function rateLimit(): RateLimitStatus
    {
        return $this->rateLimit;
    }

    public function isRetriable(): bool
    {
        return true;
    }
}
