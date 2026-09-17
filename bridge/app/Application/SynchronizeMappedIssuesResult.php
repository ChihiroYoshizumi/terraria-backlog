<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Mapping\CompletionResult;
use App\Domain\Mapping\CompletionStatus;

/**
 * 1 回の Mapping 同期 (Snapshot / Reconciliation 1 回分) の結果
 * (docs/design.md §12 手順 8-9)。
 *
 * Task 07 はこれを見て通知と再試行要否を判断する。**部分失敗を成功に丸めない**
 * ため、更新できた課題と失敗した課題を別々に保持する (AC-10)。
 */
final readonly class SynchronizeMappedIssuesResult
{
    /**
     * @param  list<CompletionResult>  $results
     */
    public function __construct(
        public string $worldKey,
        public array $results,
        /** scan した未完了 Mapping 課題の総数 (join 前)。 */
        public int $scannedMappings,
        /** Registry 完了済みの Achievement Key 数 (join の左辺)。 */
        public int $completedAchievements,
    ) {}

    /**
     * @return list<CompletionResult>
     */
    public function withStatus(CompletionStatus $status): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (CompletionResult $result): bool => $result->status === $status,
        ));
    }

    /**
     * 本同期で実際に完了へ更新した課題 (AC-14: 複数課題でもすべて含む)。
     *
     * @return list<string>
     */
    public function completedIssueKeys(): array
    {
        return array_values(array_map(
            static fn (CompletionResult $result): string => $result->issueKey,
            $this->withStatus(CompletionStatus::Completed),
        ));
    }

    /**
     * @return list<CompletionResult>
     */
    public function failures(): array
    {
        return $this->withStatus(CompletionStatus::Failed);
    }

    /**
     * 次回 reconciliation での再試行対象があるか。
     */
    public function hasFailures(): bool
    {
        return $this->failures() !== [];
    }

    public function updatedCount(): int
    {
        return count($this->withStatus(CompletionStatus::Completed));
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'world_key' => $this->worldKey,
            'scannedMappings' => $this->scannedMappings,
            'completedAchievements' => $this->completedAchievements,
            'candidates' => count($this->results),
            'completed' => $this->updatedCount(),
            'already_completed' => count($this->withStatus(CompletionStatus::AlreadyCompleted)),
            'skipped' => count($this->withStatus(CompletionStatus::Skipped)),
            'failed' => count($this->failures()),
        ];
    }
}
