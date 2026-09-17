<?php

declare(strict_types=1);

namespace App\Domain\Mapping;

/**
 * 攻略課題 Mapping 1 件の read model (docs/design.md §11, §19)。
 *
 * Backlog Issue の生配列を Application 層へ漏らさないための境界。
 * このオブジェクトが存在すること自体が、`MappingIssueMapper` で次を確認済みで
 * あることを意味する。
 *
 * 1. Issue の Project ID が validated ProjectConfiguration の Project ID と一致
 * 2. `Terraria Record Type` が null / 空文字 (registry でも未知の値でもない)
 * 3. `Terraria World Key` が非空
 * 4. `Terraria Key` が非空
 * 5. 状態 ID を読み取れている (読めない Issue を未完了と推測しない)
 *
 * `done` は validated Done Status ID との一致のみで決まる。表示名や固定値には
 * 依存しない (docs/spec.md §8)。未完了かどうかの判定は Repository / Index 側で
 * 行い、ここでは観測した値をそのまま保持する。
 */
final readonly class MappingIssue
{
    public function __construct(
        public int $projectId,
        public int $issueId,
        public string $issueKey,
        public string $worldKey,
        public string $achievementKey,
        public bool $done,
        public int $statusId,
    ) {}

    /**
     * Mapping の同一性 (docs/design.md §11) を完全一致で確認する。
     *
     * PATCH 直前の再取得で「Mapping が解除・変更されていないか」を見るために使う
     * (docs/design.md §21 Mapping 課題 / AC-15)。Backlog の検索文字列を exact
     * lookup とみなさず、必ず PHP 側で突合する。
     */
    public function matches(int $projectId, string $worldKey, string $achievementKey): bool
    {
        return $this->projectId === $projectId
            && $this->worldKey === $worldKey
            && $this->achievementKey === $achievementKey;
    }

    /**
     * 同じ物理 Issue を指しているか。Issue Key の取り違えを防ぐ。
     */
    public function isSameIssue(self $other): bool
    {
        return $this->issueId === $other->issueId && $this->issueKey === $other->issueKey;
    }

    /**
     * 構造化ログ用 (docs/design.md §17)。秘密情報は含まない。
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'projectId' => $this->projectId,
            'mapping_issue_key' => $this->issueKey,
            'world_key' => $this->worldKey,
            'achievement_key' => $this->achievementKey,
            'done' => $this->done,
        ];
    }
}
