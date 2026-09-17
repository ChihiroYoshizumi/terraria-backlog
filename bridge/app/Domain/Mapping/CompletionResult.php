<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * `MappingRepository::complete()` の戻り値 (docs/design.md §12, §19.1)。
 *
 * Task 07 はこれを見て通知・再試行を判断する。update failure を success と
 * 読み替えないよう、「更新した」と「更新していない」を型で区別する。
 */
final readonly class CompletionResult
{
    private function __construct(
        public CompletionStatus $status,
        public string $worldKey,
        public string $achievementKey,
        public string $issueKey,
        public ?CompletionFailureReason $reason,
        public ?string $detail,
    ) {}

    public static function completed(MappingIssue $mapping): self
    {
        return new self(
            CompletionStatus::Completed,
            $mapping->worldKey,
            $mapping->achievementKey,
            $mapping->issueKey,
            null,
            null,
        );
    }

    public static function alreadyCompleted(MappingIssue $mapping): self
    {
        return new self(
            CompletionStatus::AlreadyCompleted,
            $mapping->worldKey,
            $mapping->achievementKey,
            $mapping->issueKey,
            null,
            null,
        );
    }

    public static function skipped(MappingIssue $mapping, CompletionFailureReason $reason, string $detail): self
    {
        return new self(
            CompletionStatus::Skipped,
            $mapping->worldKey,
            $mapping->achievementKey,
            $mapping->issueKey,
            $reason,
            $detail,
        );
    }

    public static function failed(MappingIssue $mapping, CompletionFailureReason $reason, string $detail): self
    {
        return new self(
            CompletionStatus::Failed,
            $mapping->worldKey,
            $mapping->achievementKey,
            $mapping->issueKey,
            $reason,
            $detail,
        );
    }

    /**
     * 本同期で実際に Backlog の状態を変えたか。
     */
    public function wasUpdated(): bool
    {
        return $this->status === CompletionStatus::Completed;
    }

    /**
     * 「Backlog 上で完了している」ことを確認できたか。
     *
     * `Completed` と `AlreadyCompleted` のみ true。`Skipped` は対象外になった
     * だけで完了の保証ではないため含めない。
     */
    public function isDone(): bool
    {
        return $this->status === CompletionStatus::Completed
            || $this->status === CompletionStatus::AlreadyCompleted;
    }

    /**
     * 次回 reconciliation での再試行対象か (AC-10)。
     */
    public function isFailure(): bool
    {
        return $this->status === CompletionStatus::Failed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'world_key' => $this->worldKey,
            'achievement_key' => $this->achievementKey,
            'mapping_issue_key' => $this->issueKey,
            'result' => $this->status->value,
            'error_type' => $this->reason?->value,
            'detail' => $this->detail,
        ];
    }
}
