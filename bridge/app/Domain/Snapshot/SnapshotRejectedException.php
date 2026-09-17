<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use RuntimeException;

/**
 * Snapshot 全体を拒否したことを表す例外 (docs/design.md §6.4)。
 *
 * この例外が投げられた時点で Registry / Mapping の処理は開始していない。
 * message は運用者向けの診断文であり、秘密情報や受信値そのものを含めない。
 */
final class SnapshotRejectedException extends RuntimeException
{
    public function __construct(
        public readonly SnapshotRejectionCode $rejectionCode,
        string $detail,
    ) {
        parent::__construct($detail);
    }

    public static function because(SnapshotRejectionCode $code, string $detail): self
    {
        return new self($code, $detail);
    }

    public function httpStatus(): int
    {
        return $this->rejectionCode->httpStatus();
    }
}
