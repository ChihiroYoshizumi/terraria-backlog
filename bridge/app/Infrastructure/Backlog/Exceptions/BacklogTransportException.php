<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

/**
 * connect timeout / request timeout / 接続断 (docs/design.md §13.2, §13.4)。
 *
 * docs/spec.md §9: 保存要求がタイムアウトした場合、保存成否を再確認する。
 * 未確認の間は成功 ACK を返さない。
 *
 * 元例外 (`Illuminate\Http\Client\ConnectionException`) は `previous` に保持しない。
 * cURL のメッセージへ request 情報が混入した場合の秘密情報漏洩を避けるため、
 * redact 済みメッセージのみを持つ。
 */
final class BacklogTransportException extends BacklogApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, null, 'backlog.transport_error');
    }

    public function isRetriable(): bool
    {
        return true;
    }
}
