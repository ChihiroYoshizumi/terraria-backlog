<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Snapshot 1 件の処理結果 (docs/design.md §15.3, §18.2)。
 *
 * **response body には出さない**。body は notification-only のままであり
 * (contracts/snapshot-response-v1.schema.json)、この値は HTTP status の
 * 決定にだけ使う。
 *
 * Adapter は「通信失敗・非成功応答」でワールド単位の復旧待ちフラグを立てる
 * (docs/design.md §15.2)。Bridge が再照合を完了できなかったことを 200 で隠すと、
 * Adapter は障害を認識できず、復旧後の `periodic` / `manual` で復旧通知を
 * 出す契機も失われる。そのため「再試行が必要」を status で伝える。
 */
enum SnapshotOutcome: string
{
    /** 再照合を最後まで実行できた。通知の有無は問わない。 */
    case Completed = 'completed';

    /**
     * Backlog 障害等で再照合を完了できず、Adapter へ返せる通知も無い。
     *
     * 成功 ACK は返さない (docs/spec.md AC-09)。Adapter は次の
     * `periodic` / `manual` に `recoveryPending: true` を付けて再送する。
     */
    case RetriableFailure = 'retriable_failure';

    /**
     * docs/design.md §18.2 の「503 相当の処理結果」。
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Completed => 200,
            self::RetriableFailure => 503,
        };
    }
}
