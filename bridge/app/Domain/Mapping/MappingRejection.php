<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * Mapping として扱わなかった理由 (docs/design.md §11, §17 / AC-19)。
 *
 * TRAINING_YOSHIZUMI にはこのシステムと無関係な研修課題が同居する。
 * 「Mapping ではない」と判定した根拠を機械可読に残し、**推測で一般課題を
 * 更新しない**ことを診断できるようにする。
 *
 * `silent` が true の理由は運用上の正常系であり、課題ごとのログを出さない
 * (docs/specs/backlog-mapping.md:「属性未設定をエラーにしない」)。
 */
enum MappingRejection: string
{
    /** 対象 Project ID と一致しない Issue。 */
    case NotTargetProject = 'mapping.not_target_project';

    /** `Terraria Record Type === registry`。Registry として扱い Mapping から除外する。 */
    case RegistryRecord = 'mapping.registry_record';

    /** `foo` 等の未知の非空 Record Type。攻略課題と推測せず無視する。 */
    case InvalidRecordType = 'mapping.invalid_record_type';

    /** World Key / Terraria Key の片方だけ設定されている。 */
    case PartialMapping = 'mapping.partial_mapping';

    /** Mapping 属性が無い一般 / 研修課題。 */
    case NoMapping = 'mapping.no_mapping';

    /** Issue ID / Issue Key / 状態 ID を読み取れない応答。未完了と推測しない。 */
    case Unreadable = 'mapping.unreadable_issue';

    /**
     * 課題ごとの診断ログを出すべきか。
     *
     * 一般 / 研修課題 (`NoMapping`) と Registry (`RegistryRecord`) は同居が前提の
     * 正常系なので silent とする。「片方だけ」「未知の Record Type」は設定ミスの
     * 可能性があるため必ずログへ残す (docs/design.md §11)。
     */
    public function shouldLog(): bool
    {
        return match ($this) {
            self::InvalidRecordType, self::PartialMapping, self::Unreadable => true,
            self::NotTargetProject, self::RegistryRecord, self::NoMapping => false,
        };
    }
}
