<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Mapping\CompletionFailureReason;
use App\Domain\Mapping\CompletionResult;
use App\Domain\Mapping\MappingIndex;
use App\Domain\Mapping\MappingIssue;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Snapshot\WorldKey;
use RuntimeException;
use Throwable;

/**
 * Backlog へ一切接続しない Mapping Repository のテストダブル。
 *
 * - `addMapping()` で「未完了の攻略課題」を用意する。
 * - `complete()` は 1 回で完了させ、`patches` に記録する。完了済みには触らない。
 * - `failWith()` で PATCH 失敗 (AC-10) を仕込める。
 */
final class FakeMappingRepository implements MappingRepository
{
    public const int PROJECT_ID = FakeRegistryRepository::PROJECT_ID;

    /** @var list<array{issue: MappingIssue, done: bool}> */
    private array $issues = [];

    /** @var list<string> loadIncompleteIndex の呼び出し回数分の worldKey */
    public array $indexLoads = [];

    /** @var list<string> 実際に PATCH した Issue Key */
    public array $patches = [];

    /** @var array<string, CompletionFailureReason> issueKey => 失敗理由 */
    private array $failures = [];

    public ?Throwable $indexLoadException = null;

    private int $nextId = 800;

    public function addMapping(string $worldKey, string $achievementKey, string $issueKey): MappingIssue
    {
        $issue = new MappingIssue(
            projectId: self::PROJECT_ID,
            issueId: $this->nextId++,
            issueKey: $issueKey,
            worldKey: $worldKey,
            achievementKey: $achievementKey,
            done: false,
            statusId: 1,
        );

        $this->issues[] = ['issue' => $issue, 'done' => false];

        return $issue;
    }

    public function failWith(string $issueKey, CompletionFailureReason $reason): self
    {
        $this->failures[$issueKey] = $reason;

        return $this;
    }

    public function breakIndexLoad(string $message = 'backlog is unavailable'): self
    {
        $this->indexLoadException = new RuntimeException($message);

        return $this;
    }

    public function loadIncompleteIndex(WorldKey $world): MappingIndex
    {
        if ($this->indexLoadException !== null) {
            throw $this->indexLoadException;
        }

        $this->indexLoads[] = $world->value;

        $index = new MappingIndex($world, self::PROJECT_ID);

        foreach ($this->issues as $entry) {
            // 完了済みは index に入れない (docs/design.md §11)。
            if ($entry['done'] || $entry['issue']->worldKey !== $world->value) {
                continue;
            }

            $index->add($entry['issue']);
        }

        return $index;
    }

    public function complete(MappingIssue $mapping): CompletionResult
    {
        if (isset($this->failures[$mapping->issueKey])) {
            return CompletionResult::failed($mapping, $this->failures[$mapping->issueKey], 'fake failure');
        }

        foreach ($this->issues as $index => $entry) {
            if ($entry['issue']->issueKey !== $mapping->issueKey) {
                continue;
            }

            if ($entry['done']) {
                // AC-11: 既に完了しているなら PATCH しない。
                return CompletionResult::alreadyCompleted($mapping);
            }

            $this->issues[$index]['done'] = true;
            $this->patches[] = $mapping->issueKey;

            return CompletionResult::completed($mapping);
        }

        return CompletionResult::skipped($mapping, CompletionFailureReason::MappingChanged, 'issue is gone');
    }
}
