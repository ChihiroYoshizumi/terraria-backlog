<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Domain\Registry\RegistryIssue;
use App\Domain\Registry\RegistryRecordType;

/**
 * Backlog Issue (生 JSON) -> Registry read model の変換 (docs/design.md §10.3)。
 *
 * ここが「PHP 側の final match」の実装であり、Backlog API の検索文字列を
 * exact lookup とみなさないための関門。次をすべて満たさない Issue は Registry と
 * みなさず `null` を返す。
 *
 * 1. Issue の Project ID が validated ProjectConfiguration の Project ID と一致
 * 2. `Terraria Record Type === registry`
 * 3. `Terraria World Key` が非空
 * 4. `Terraria Key` が非空
 *
 * Project ID は必ず validated config 由来の値と比較する。Adapter payload や
 * Backlog の検索条件を信用しない (docs/design.md §16.3)。
 */
final readonly class RegistryIssueMapper
{
    public function __construct(private ProjectConfiguration $configuration) {}

    /**
     * @param  array<string, mixed>  $issue  Backlog API の Issue オブジェクト
     */
    public function map(array $issue): ?RegistryIssue
    {
        $projectId = self::intOrNull($issue['projectId'] ?? null);

        // 1. Project ID の完全一致。API filter を信用せず PHP でも確認する。
        if ($projectId === null || $projectId !== $this->configuration->projectId) {
            return null;
        }

        $issueId = self::intOrNull($issue['id'] ?? null);
        $issueKey = is_string($issue['issueKey'] ?? null) ? $issue['issueKey'] : null;

        if ($issueId === null || $issueKey === null || $issueKey === '') {
            return null;
        }

        $customFields = $issue['customFields'] ?? null;
        $values = is_array($customFields) ? self::indexCustomFields($customFields) : [];

        $recordType = $values[$this->configuration->recordTypeFieldId] ?? null;

        // 2. Registry 以外 (空欄の攻略課題 / 未知の非空値) は Registry として扱わない。
        if ($recordType !== RegistryRecordType::VALUE) {
            return null;
        }

        $worldKey = $values[$this->configuration->worldKeyFieldId] ?? null;
        $achievementKey = $values[$this->configuration->achievementKeyFieldId] ?? null;

        // 3/4. 片方だけ設定された Registry は論理 Primary Key を構成できない。
        if ($worldKey === null || $worldKey === '' || $achievementKey === null || $achievementKey === '') {
            return null;
        }

        $statusId = self::intOrNull(is_array($issue['status'] ?? null) ? ($issue['status']['id'] ?? null) : null);

        return new RegistryIssue(
            projectId: $projectId,
            issueId: $issueId,
            issueKey: $issueKey,
            recordType: $recordType,
            worldKey: $worldKey,
            achievementKey: $achievementKey,
            // 完了判定は validated Done Status ID のみ。表示名や固定値を前提にしない
            // (docs/spec.md §8)。status を読めない応答は「完了」と断定しない。
            done: $statusId !== null && $statusId === $this->configuration->doneStatusId,
            statusId: $statusId,
        );
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
