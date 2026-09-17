<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

/**
 * Backlog 側の 5xx (docs/design.md §13.4)。
 *
 * Registry の存在確認ができなければ未登録と断定しない。
 */
final class BacklogServerException extends BacklogApiException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status, 'backlog.server_error');
    }

    public function isRetriable(): bool
    {
        return true;
    }
}
