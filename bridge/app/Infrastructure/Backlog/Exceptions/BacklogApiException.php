<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

use RuntimeException;

/**
 * Backlog API 呼び出しの失敗 (docs/design.md §13.3, §13.4)。
 *
 * 失敗を「該当0件」「登録済みでない」と解釈してはならない (docs/design.md §20)。
 * 呼び出し側は本例外を捕捉した場合、成功 ACK を返さず次回 reconciliation に委ねる。
 *
 * 本例外は API Key を保持しない。メッセージは必ず呼び出し元で redact 済みの
 * 文字列を渡す (docs/design.md §16.2)。
 */
class BacklogApiException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $status = null,
        private readonly string $errorType = 'backlog.api_error',
    ) {
        parent::__construct($message);
    }

    public function status(): ?int
    {
        return $this->status;
    }

    /**
     * 構造化ログ用の error_type (docs/design.md §17)。
     */
    public function errorType(): string
    {
        return $this->errorType;
    }

    /**
     * 次回 periodic / manual reconciliation で再評価してよい失敗か。
     */
    public function isRetriable(): bool
    {
        return false;
    }
}
