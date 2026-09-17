<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * 1 Issue を Mapping として評価した結果 (docs/design.md §11)。
 *
 * 「Mapping だった / でなかった」だけでなく **なぜ違ったか** を返す。
 * 無視した課題を診断ログに残しつつ、状態は一切変えないため。
 */
final readonly class MappingCandidate
{
    private function __construct(
        public ?MappingIssue $issue,
        public ?MappingRejection $rejection,
        public ?string $issueKey,
        /** 診断用に観測した `Terraria Record Type` の生値 (未設定なら null)。 */
        public ?string $recordType,
    ) {}

    public static function accepted(MappingIssue $issue): self
    {
        return new self($issue, null, $issue->issueKey, '');
    }

    public static function rejected(
        MappingRejection $rejection,
        ?string $issueKey = null,
        ?string $recordType = null,
    ): self {
        return new self(null, $rejection, $issueKey, $recordType);
    }

    public function isAccepted(): bool
    {
        return $this->issue !== null;
    }

    /**
     * 構造化ログ用 (docs/design.md §17)。Issue の本文・件名は載せない。
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'mapping_issue_key' => $this->issueKey,
            'error_type' => $this->rejection?->value,
            // 未知の Record Type は原因調査に必要なので載せる。長さは切り詰める。
            'recordType' => $this->recordType === null ? null : mb_substr($this->recordType, 0, 64),
        ];
    }
}
