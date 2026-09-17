<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

use App\Domain\Achievement\AchievementKey;
use App\Domain\Snapshot\WorldKey;
use InvalidArgumentException;

/**
 * Snapshot-scoped Mapping Index (docs/design.md §11, §12)。
 *
 * ```text
 * mappingIndex[achievement_key] = MappingIssue[]
 * ```
 *
 * Snapshot 処理の開始時に対象 Project / World の **未完了** 攻略課題を一度だけ
 * 全ページ取得してこの index を作る。Achievement ごとに full scan を繰り返さない
 * ための構造であり、Snapshot 内に Achievement が何件あっても scan は 1 回で済む
 * (docs/design.md §12, §20)。
 *
 * 不変条件:
 *
 * - 対象 Project の Issue しか入らない。
 * - 対象 World の Issue しか入らない (AC-13: 他ワールドの達成で完了させない)。
 * - **完了済み Issue は入らない**。完了済みの攻略課題・研修課題は同期対象外であり、
 *   状態・本文・属性・コメントを変更しない (AC-19, docs/specs/backlog-sync.md)。
 * - 1 Achievement に複数課題を許可する (AC-14)。
 */
final class MappingIndex
{
    /** @var array<string, list<MappingIssue>> */
    private array $byAchievementKey = [];

    /**
     * @param  iterable<MappingIssue>  $issues
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
     * exact 一致を確認済みの未完了 Mapping を追加する。
     *
     * Project / World / 完了状態の不一致はプログラミングエラーとして即座に落とす。
     * 混入は「無関係な研修課題を完了にする」事故に直結する。
     */
    public function add(MappingIssue $issue): void
    {
        if ($issue->projectId !== $this->projectId) {
            throw new InvalidArgumentException('mapping index には対象 Project の Issue しか登録できない.');
        }

        if ($issue->worldKey !== $this->world->value) {
            throw new InvalidArgumentException('mapping index には対象 World の Issue しか登録できない.');
        }

        if ($issue->done) {
            throw new InvalidArgumentException('mapping index には未完了の Issue しか登録できない.');
        }

        $this->byAchievementKey[$issue->achievementKey][] = $issue;
    }

    /**
     * 指定 Achievement Key に紐付く未完了攻略課題。
     *
     * @return list<MappingIssue>
     */
    public function forKey(AchievementKey|string $key): array
    {
        return $this->byAchievementKey[self::keyString($key)] ?? [];
    }

    /**
     * 完了済み Registry set との join (docs/design.md §12 手順 8)。
     *
     * Registry 未達成の Achievement Key は結果に現れない = 課題を変更しない
     * (AC-07)。同じ key を重複して渡されても 1 回だけ評価する。
     *
     * @param  iterable<AchievementKey|string>  $completedAchievementKeys
     * @return list<MappingIssue>
     */
    public function forCompletedAchievements(iterable $completedAchievementKeys): array
    {
        $issues = [];
        $seenKeys = [];
        $seenIssueIds = [];

        foreach ($completedAchievementKeys as $key) {
            $keyString = self::keyString($key);

            if (isset($seenKeys[$keyString])) {
                continue;
            }

            $seenKeys[$keyString] = true;

            foreach ($this->forKey($keyString) as $issue) {
                // 同一 Issue が二重に完了処理へ回らないようにする。
                if (isset($seenIssueIds[$issue->issueId])) {
                    continue;
                }

                $seenIssueIds[$issue->issueId] = true;
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    public function has(AchievementKey|string $key): bool
    {
        return $this->forKey($key) !== [];
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
