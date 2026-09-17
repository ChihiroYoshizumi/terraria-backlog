<?php

declare(strict_types=1);

namespace App\Domain\Registry;

use App\Domain\Achievement\AchievementKey;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;

/**
 * Snapshot-scoped Registry Index (docs/design.md §10.3)。
 *
 * ```text
 * registryIndex[achievement_key] = RegistryIssue[]
 * ```
 *
 * Snapshot 処理の開始時に対象 Project / World の Registry を一度だけ全ページ取得し、
 * この index を作る。Achievement ごとに全件検索を繰り返さないための構造であり、
 * Snapshot 内に Item が何個あっても scan は 1 回で済む。
 *
 * index は単一 World に閉じる。World A の Registry を World B の判定に使うと
 * AC-13 に違反するため、追加時に World Key の一致を強制する。
 *
 * 書き込み後の保存確認結果を反映するため mutable とする。
 */
final class RegistryIndex
{
    /** @var array<string, list<RegistryIssue>> */
    private array $byAchievementKey = [];

    /**
     * @param  iterable<RegistryIssue>  $issues
     */
    public function __construct(
        public readonly WorldKey $world,
        public readonly int $projectId,
        iterable $issues = [],
    ) {
        foreach ($issues as $issue) {
            $this->add($issue);
        }
    }

    /**
     * exact 一致した Registry を追加する。
     *
     * Project / World の不一致はプログラミングエラーとして即座に落とす。
     * 混入は「World A の記録で World B を完了にする」事故に直結する。
     */
    public function add(RegistryIssue $issue): void
    {
        if ($issue->projectId !== $this->projectId) {
            throw new InvalidArgumentException('registry index には対象 Project の Issue しか登録できない.');
        }

        if ($issue->worldKey !== $this->world->value) {
            throw new InvalidArgumentException('registry index には対象 World の Issue しか登録できない.');
        }

        if ($issue->recordType !== RegistryRecordType::VALUE) {
            throw new InvalidArgumentException('registry index には Terraria Record Type=registry の Issue しか登録できない.');
        }

        $this->byAchievementKey[$issue->achievementKey][] = $issue;
    }

    /**
     * 指定 Achievement Key の exact Registry。
     *
     * @return list<RegistryIssue>
     */
    public function forKey(AchievementKey|string $key): array
    {
        return $this->byAchievementKey[self::keyString($key)] ?? [];
    }

    /**
     * 保存確認後の状態で置き換える (docs/design.md §10.4 の「registryIndex を更新」)。
     *
     * @param  list<RegistryIssue>  $issues
     */
    public function replace(AchievementKey|string $key, array $issues): void
    {
        $keyString = self::keyString($key);

        unset($this->byAchievementKey[$keyString]);

        foreach ($issues as $issue) {
            if ($issue->achievementKey !== $keyString) {
                throw new InvalidArgumentException('registry index の key と Issue の Terraria Key が一致しない.');
            }

            $this->add($issue);
        }
    }

    public function has(AchievementKey|string $key): bool
    {
        return $this->forKey($key) !== [];
    }

    /**
     * 完了済み Registry が 1 件以上ある Achievement Key。
     *
     * Task 06 / 07 が「達成事実として確定しているもの」を Mapping と突合するために使う。
     *
     * @return list<string>
     */
    public function completedAchievementKeys(): array
    {
        $keys = [];

        foreach ($this->byAchievementKey as $key => $issues) {
            foreach ($issues as $issue) {
                if ($issue->done) {
                    $keys[] = (string) $key;

                    break;
                }
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function achievementKeys(): array
    {
        $keys = array_map(strval(...), array_keys($this->byAchievementKey));
        sort($keys);

        return $keys;
    }

    public function count(): int
    {
        $total = 0;

        foreach ($this->byAchievementKey as $issues) {
            $total += count($issues);
        }

        return $total;
    }

    private static function keyString(AchievementKey|string $key): string
    {
        return $key instanceof AchievementKey ? $key->toString() : $key;
    }
}
