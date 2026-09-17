<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * Snapshot 全体を拒否する理由 (docs/design.md §6.4「リクエスト全体を拒否する validation」)。
 *
 * HTTP status は design の意味を崩さない範囲で使い分ける。
 *
 * - `403`: docs/design.md §5.2「リクエスト URL・payload の world.key・許可リストの
 *   3 つが一致しない場合は 403」。
 * - `409`: docs/design.md §2.3「PHP も TERRARIA_SUPPORTED_RUNTIME 設定と一致しない
 *   Snapshot を 409 で拒否する」。
 * - `413`: Request Body のバイト数上限超過。
 * - `400`: JSON として読めない (malformed)。
 * - `503`: PHP 側の設定不備。誤った更新を避けるため fail closed とする (§18.4)。
 * - それ以外の envelope 構造・件数上限・禁止 field は `422`。
 */
enum SnapshotRejectionCode: string
{
    case BridgeMisconfigured = 'bridge.misconfigured';

    case BodyTooLarge = 'request.body_too_large';
    case MalformedJson = 'request.malformed_json';
    case EnvelopeNotObject = 'envelope.not_an_object';

    case UnsupportedSchemaVersion = 'envelope.unsupported_schema_version';
    case InvalidRequestId = 'envelope.invalid_request_id';
    case InvalidReason = 'envelope.invalid_reason';
    case InvalidObservedAt = 'envelope.invalid_observed_at';
    case InvalidRecoveryPending = 'envelope.invalid_recovery_pending';

    case InvalidRuntime = 'runtime.invalid';
    case UnsupportedRuntime = 'runtime.unsupported';

    case InvalidWorld = 'world.invalid';
    case InvalidTerrariaWorldId = 'world.invalid_terraria_world_id';
    case WorldKeyMismatch = 'world.key_mismatch';
    case WorldNotAllowed = 'world.not_allowed';

    case CollectionChestNameMismatch = 'collection_chest.name_mismatch';
    case InvalidCollectionChests = 'collection_chest.invalid_container';

    case InvalidFlags = 'flags.invalid';
    case InvalidTrigger = 'trigger.invalid';

    case LimitExceeded = 'limit.exceeded';
    case ForbiddenField = 'input.forbidden_field';

    public function httpStatus(): int
    {
        return match ($this) {
            self::BridgeMisconfigured => 503,
            self::BodyTooLarge => 413,
            self::MalformedJson => 400,
            self::UnsupportedRuntime => 409,
            self::WorldKeyMismatch, self::WorldNotAllowed => 403,
            default => 422,
        };
    }
}
