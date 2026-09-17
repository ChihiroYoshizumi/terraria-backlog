<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

/**
 * 認証・権限エラー (401 / 403)。
 *
 * docs/spec.md §9: 認証・権限・設定のエラーは管理者に診断情報を残す。
 * 再試行しても解消しないため retriable ではない。
 */
final class BacklogAuthenticationException extends BacklogApiException
{
    public function __construct(string $message, int $status)
    {
        parent::__construct($message, $status, 'backlog.authentication_failed');
    }
}
