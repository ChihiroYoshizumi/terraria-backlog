<?php

declare(strict_types=1);

namespace App\Domain\Registry;

/**
 * Registry 1 件の read model (docs/design.md §10.1, §19)。
 *
 * Backlog Issue の生配列を Application 層へ漏らさないための境界。
 * ここに載るのは「Registry として完全一致を確認済み」の情報だけであり、
 * このオブジェクトが存在すること自体が次を意味する。
 *
 * 1. Project ID が validated ProjectConfiguration の Project ID と一致
 * 2. `Terraria Record Type === registry`
 * 3. `Terraria World Key` / `Terraria Key` が非空
 *
 * World / Achievement の突合は生成元 (RegistryIssueMapper) ではなく
 * Repository 側で行う。ここでは値をそのまま保持する。
 */
final readonly class RegistryIssue
{
    public function __construct(
        public int $projectId,
        public int $issueId,
        public string $issueKey,
        public string $recordType,
        public string $worldKey,
        public string $achievementKey,
        public bool $done,
        public ?int $statusId = null,
    ) {}

    /**
     * 論理 Primary Key (docs/design.md §10.2) の完全一致判定。
     *
     * Backlog API の文字列 filter を exact lookup とみなさず、PHP 側で必ず通す。
     */
    public function matches(int $projectId, string $worldKey, string $achievementKey): bool
    {
        return $this->projectId === $projectId
            && $this->recordType === RegistryRecordType::VALUE
            && $this->worldKey === $worldKey
            && $this->achievementKey === $achievementKey;
    }

    /**
     * 構造化ログ用。秘密情報は含まない。
     *
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return [
            'projectId' => $this->projectId,
            'issueId' => $this->issueId,
            'issueKey' => $this->issueKey,
            'worldKey' => $this->worldKey,
            'achievementKey' => $this->achievementKey,
            'done' => $this->done,
        ];
    }
}
