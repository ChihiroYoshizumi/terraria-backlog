<?php

declare(strict_types=1);

namespace App\Domain\Registry;

use App\Domain\Achievement\Achievement;
use App\Domain\Snapshot\WorldKey;

/**
 * `ensureRegistered` の戻り値 (docs/design.md §10.4, §19.1)。
 *
 * Task 07 はこれを見て ACK / 通知の可否を判断する。判断に必要なのは
 * 「達成事実が Backlog 上で確定しているか」であり、それを `isPersisted()` で表す。
 *
 * 保存確認が取れていない状態を success と断定しない
 * (docs/spec.md §9, AC-03 / AC-12)。
 */
final readonly class RegistryResult
{
    private function __construct(
        public RegistryStatus $status,
        public string $worldKey,
        public string $achievementKey,
        public ?RegistryIssue $issue,
        public ?RegistryFailureReason $reason,
        public ?string $detail,
        /** @var list<RegistryIssue> 検出した exact Registry (物理重複を含む) */
        public array $duplicates,
    ) {}

    public static function registered(WorldKey $world, Achievement $achievement, RegistryIssue $issue): self
    {
        return new self(
            RegistryStatus::Registered,
            $world->value,
            $achievement->keyString(),
            $issue,
            null,
            null,
            [$issue],
        );
    }

    /**
     * @param  list<RegistryIssue>  $duplicates
     */
    public static function alreadyRegistered(
        WorldKey $world,
        Achievement $achievement,
        RegistryIssue $issue,
        array $duplicates = [],
    ): self {
        return new self(
            RegistryStatus::AlreadyRegistered,
            $world->value,
            $achievement->keyString(),
            $issue,
            null,
            null,
            $duplicates === [] ? [$issue] : $duplicates,
        );
    }

    /**
     * @param  list<RegistryIssue>  $duplicates
     */
    public static function failed(
        WorldKey $world,
        Achievement $achievement,
        RegistryFailureReason $reason,
        string $detail,
        array $duplicates = [],
    ): self {
        return new self(
            RegistryStatus::Failed,
            $world->value,
            $achievement->keyString(),
            null,
            $reason,
            $detail,
            $duplicates,
        );
    }

    /**
     * Backlog 上に完了済み Registry があることを確認できたか。
     *
     * true のときだけ成功 ACK / 攻略課題の完了同期へ進んでよい。
     */
    public function isPersisted(): bool
    {
        return $this->status !== RegistryStatus::Failed;
    }

    /**
     * 本 Snapshot で実際に書き込みを行ったか (ACK 文言の出し分け用)。
     */
    public function wasWritten(): bool
    {
        return $this->status === RegistryStatus::Registered;
    }

    /**
     * 物理重複を検出したか。検出しても削除・マージはしない
     * (docs/design.md §10.4, docs/spec.md AC-12)。
     */
    public function hasPhysicalDuplicates(): bool
    {
        return count($this->duplicates) > 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'worldKey' => $this->worldKey,
            'achievementKey' => $this->achievementKey,
            'result' => $this->status->value,
            'reason' => $this->reason?->value,
            'detail' => $this->detail,
            'issueKey' => $this->issue?->issueKey,
            'duplicateCount' => count($this->duplicates),
        ];
    }
}
