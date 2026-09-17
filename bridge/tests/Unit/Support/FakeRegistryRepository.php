<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Domain\Achievement\Achievement;
use App\Domain\Registry\RegistryFailureReason;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Registry\RegistryIssue;
use App\Domain\Registry\RegistryRecordType;
use App\Domain\Registry\RegistryRepository;
use App\Domain\Registry\RegistryResult;
use App\Domain\Snapshot\WorldKey;
use RuntimeException;
use Throwable;

/**
 * Backlog へ一切接続しない Registry Repository のテストダブル。
 *
 * Task 05 の実装 (`BacklogRegistryRepository`) の振る舞いを最小限に再現する。
 *
 * - 「保存確認済み」= `done` な Registry が存在すること。
 * - 保存を確認できないケースは `failWith()` で Achievement Key ごとに仕込む。
 * - 書き込み回数を `writes` に記録し、冪等性を検証できるようにする。
 */
final class FakeRegistryRepository implements RegistryRepository
{
    public const int PROJECT_ID = 4242;

    /** @var array<string, bool> achievementKey => done 済み Registry があるか */
    public array $stored = [];

    /** @var list<string> loadIndex で読み込んだ回数分の worldKey */
    public array $indexLoads = [];

    /** @var list<string> 実際に書き込んだ Achievement Key */
    public array $writes = [];

    /** @var list<string> ensureRegistered を呼ばれた Achievement Key */
    public array $ensureCalls = [];

    /** @var array<string, RegistryFailureReason> achievementKey => 失敗理由 */
    private array $failures = [];

    /** @var array<string, bool> 物理重複を検出したことにする Achievement Key */
    private array $duplicates = [];

    public ?Throwable $indexLoadException = null;

    private int $nextId = 500;

    /**
     * 既に Backlog 上に完了済み Registry がある状態にする。
     */
    public function seedCompleted(string ...$achievementKeys): self
    {
        foreach ($achievementKeys as $key) {
            $this->stored[$key] = true;
        }

        return $this;
    }

    /**
     * 保存確認が取れないようにする (Backlog 5xx / timeout / lock 失敗など)。
     */
    public function failWith(string $achievementKey, RegistryFailureReason $reason): self
    {
        $this->failures[$achievementKey] = $reason;

        return $this;
    }

    /**
     * 物理重複があり完了済みが 0 件の fail closed を再現する
     * (docs/design.md §10.4)。
     */
    public function failClosedOnDuplicates(string $achievementKey): self
    {
        $this->failures[$achievementKey] = RegistryFailureReason::DuplicateIncomplete;
        $this->duplicates[$achievementKey] = true;

        return $this;
    }

    public function loadIndex(WorldKey $world): RegistryIndex
    {
        if ($this->indexLoadException !== null) {
            throw $this->indexLoadException;
        }

        $this->indexLoads[] = $world->value;

        $index = new RegistryIndex($world, self::PROJECT_ID);

        foreach (array_keys($this->stored) as $key) {
            $index->add($this->issue($world, (string) $key));
        }

        return $index;
    }

    public function ensureRegistered(WorldKey $world, Achievement $achievement, RegistryIndex $index): RegistryResult
    {
        $key = $achievement->keyString();
        $this->ensureCalls[] = $key;

        if (isset($this->failures[$key])) {
            $duplicates = isset($this->duplicates[$key])
                ? [$this->issue($world, $key, done: false), $this->issue($world, $key, done: false)]
                : [];

            return RegistryResult::failed($world, $achievement, $this->failures[$key], 'fake failure', $duplicates);
        }

        if (($this->stored[$key] ?? false) === true) {
            // AC-11: 完了済みへは書き込まない。
            return RegistryResult::alreadyRegistered($world, $achievement, $index->forKey($key)[0] ?? $this->issue($world, $key));
        }

        $this->writes[] = $key;
        $this->stored[$key] = true;

        $issue = $this->issue($world, $key);
        $index->replace($key, [$issue]);

        return RegistryResult::registered($world, $achievement, $issue);
    }

    /**
     * Backlog 障害で index scan が落ちる状態にする。
     */
    public function breakIndexLoad(string $message = 'backlog is unavailable'): self
    {
        $this->indexLoadException = new RuntimeException($message);

        return $this;
    }

    private function issue(WorldKey $world, string $achievementKey, bool $done = true): RegistryIssue
    {
        return new RegistryIssue(
            projectId: self::PROJECT_ID,
            issueId: $this->nextId++,
            issueKey: 'TRAINING_YOSHIZUMI-'.$this->nextId,
            recordType: RegistryRecordType::VALUE,
            worldKey: $world->value,
            achievementKey: $achievementKey,
            done: $done,
        );
    }
}
