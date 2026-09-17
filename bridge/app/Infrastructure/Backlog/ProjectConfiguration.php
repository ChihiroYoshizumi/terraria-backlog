<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

/**
 * 検証済みの Backlog Project 設定 (docs/design.md §9.4)。
 *
 * 本オブジェクトが存在することは、`terraria:doctor` 相当の検証を通過し、
 * ここに含まれる ID がすべて対象 Project 上で有効だと確認済みであることを意味する。
 * Task 05 (Registry Repository) / Task 06 (Mapping Repository) は Project Key や
 * Field ID を自前で env から読まず、本オブジェクトから受け取る。
 *
 * Adapter payload 由来の Project Key / Field ID / Status ID は信用しない
 * (docs/design.md §16.3)。
 */
final readonly class ProjectConfiguration
{
    public function __construct(
        public int $projectId,
        public string $projectKey,
        public int $recordTypeFieldId,
        public int $worldKeyFieldId,
        public int $achievementKeyFieldId,
        public int $doneStatusId,
        public int $registryIssueTypeId,
        public int $registryPriorityId,
    ) {}

    /**
     * Registry / Mapping の判定に使う3 Custom Field の ID。
     *
     * @return array{record_type: int, world_key: int, achievement_key: int}
     */
    public function customFieldIds(): array
    {
        return [
            'record_type' => $this->recordTypeFieldId,
            'world_key' => $this->worldKeyFieldId,
            'achievement_key' => $this->achievementKeyFieldId,
        ];
    }
}
