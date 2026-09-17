<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

/**
 * 401 / 403 / 429 以外の 4xx。
 *
 * 404 を含む。404 を「該当0件」と読み替えない (docs/design.md §20)。
 * 呼び出し側が意味付けする場合は `status()` を参照する。
 */
final class BacklogRequestException extends BacklogApiException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status, 'backlog.request_error');
    }

    public function isNotFound(): bool
    {
        return $this->status() === 404;
    }
}
