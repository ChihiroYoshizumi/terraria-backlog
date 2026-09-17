<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Domain\Mapping\MappingCandidate;
use App\Domain\Mapping\MappingIssue;
use App\Domain\Mapping\MappingRejection;
use App\Domain\Registry\RegistryRecordType;

/**
 * Backlog Issue (生 JSON) -> Mapping read model の変換 (docs/design.md §11)。
 *
 * ここが「PHP 側の final match」の実装であり、Backlog API の検索文字列を
 * exact lookup とみなさないための関門。TRAINING_YOSHIZUMI にはこのシステムと
 * 無関係な研修課題が同居するため、**少しでも条件を満たさないものは Mapping と
 * 推測しない**。
 *
 * Mapping と認める条件 (すべて必須):
 *
 * 1. Issue の Project ID が validated ProjectConfiguration の Project ID と一致
 * 2. Issue ID / Issue Key / 状態 ID を読み取れる
 * 3. `Terraria Record Type` が null / 空文字
 * 4. `Terraria World Key` が非空
 * 5. `Terraria Key` が非空
 *
 * 満たさない場合は理由付きで reject する。呼び出し側は理由に応じて
 * 「silent ignore」か「診断ログ」を選ぶだけで、状態は変更しない。
 *
 * 完了済みかどうかはここでは判定材料にせず `MappingIssue::$done` として観測結果を
 * そのまま返す。同期対象から外すのは Repository の責務 (docs/design.md §12.1)。
 */
final readonly class MappingIssueMapper
{
    public function __construct(private ProjectConfiguration $configuration) {}

    /**
     * @param  array<string, mixed>  $issue  Backlog API の Issue オブジェクト
     */
    public function map(array $issue): MappingCandidate
    {
        $issueKey = is_string($issue['issueKey'] ?? null) && $issue['issueKey'] !== ''
            ? $issue['issueKey']
            : null;

        $projectId = self::intOrNull($issue['projectId'] ?? null);

        // 1. Project ID の完全一致。API filter を信用せず PHP でも確認する
        //    (docs/design.md §16.3)。
        if ($projectId === null || $projectId !== $this->configuration->projectId) {
            return MappingCandidate::rejected(MappingRejection::NotTargetProject, $issueKey);
        }

        $issueId = self::intOrNull($issue['id'] ?? null);
        $statusId = self::intOrNull(is_array($issue['status'] ?? null) ? ($issue['status']['id'] ?? null) : null);

        // 2. 状態 ID を読めない Issue を「未完了」と推測しない。読めない = 対象外。
        if ($issueId === null || $issueKey === null || $statusId === null) {
            return MappingCandidate::rejected(MappingRejection::Unreadable, $issueKey);
        }

        $customFields = $issue['customFields'] ?? null;
        $values = is_array($customFields) ? self::indexCustomFields($customFields) : [];

        $recordType = $values[$this->configuration->recordTypeFieldId] ?? null;

        // 3. Record Type。registry は Registry、未知の非空値は invalid として無視する。
        if ($recordType !== null && $recordType !== '') {
            return MappingCandidate::rejected(
                $recordType === RegistryRecordType::VALUE
                    ? MappingRejection::RegistryRecord
                    : MappingRejection::InvalidRecordType,
                $issueKey,
                $recordType,
            );
        }

        $worldKey = $values[$this->configuration->worldKeyFieldId] ?? null;
        $achievementKey = $values[$this->configuration->achievementKeyFieldId] ?? null;

        $hasWorldKey = $worldKey !== null && $worldKey !== '';
        $hasAchievementKey = $achievementKey !== null && $achievementKey !== '';

        // 4/5. どちらも無ければ Mapping のない一般 / 研修課題。エラーにしない。
        if (! $hasWorldKey && ! $hasAchievementKey) {
            return MappingCandidate::rejected(MappingRejection::NoMapping, $issueKey);
        }

        // 片方だけは invalid mapping。ログに残すが更新しない (docs/design.md §11)。
        if (! $hasWorldKey || ! $hasAchievementKey) {
            return MappingCandidate::rejected(MappingRejection::PartialMapping, $issueKey);
        }

        return MappingCandidate::accepted(new MappingIssue(
            projectId: $projectId,
            issueId: $issueId,
            issueKey: $issueKey,
            worldKey: (string) $worldKey,
            achievementKey: (string) $achievementKey,
            // 完了判定は validated Done Status ID のみ。表示名や固定値を前提にしない
            // (docs/spec.md §8)。
            done: $statusId === $this->configuration->doneStatusId,
            statusId: $statusId,
        ));
    }

    /**
     * @param  array<array-key, mixed>  $customFields
     * @return array<int, string>
     */
    private static function indexCustomFields(array $customFields): array
    {
        $values = [];

        foreach ($customFields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $id = self::intOrNull($field['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $value = $field['value'] ?? null;

            if (! is_string($value)) {
                continue;
            }

            // Backlog の Text field は前後空白を保持しうる。突合の揺れを避けるため
            // trim した値で比較する。空白のみの値は「未設定」と同義に倒す。
            $values[$id] = trim($value);
        }

        return $values;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
