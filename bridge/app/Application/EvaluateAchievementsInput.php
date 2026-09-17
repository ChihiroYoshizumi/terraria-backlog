<?php

declare(strict_types=1);

namespace App\Application;

/**
 * Achievement 評価に必要な、validated Snapshot の部分ビュー。
 *
 * Task 02 が作る Snapshot DTO へ直接依存すると Task 間で型が循環するため、
 * evaluator 側は「envelope validation を通過済みの値」だけを受け取る最小の入力型を持つ。
 * Task 07 の統合で Snapshot DTO からこの型を組み立てて接続する
 * (`fromValidatedSnapshot()` は Task 02 が array を返す場合の既定の組み立て口)。
 *
 * ここへ渡る時点で docs/design.md §6.4 の「リクエスト全体を拒否する validation」
 * (schemaVersion / runtime version / world.key allowlist / chest 構造など) は
 * 通過している前提。この型は Item 値の正しさは前提にしない。
 */
final readonly class EvaluateAchievementsInput
{
    /**
     * @param  string  $worldKey  診断ログの帰属先 (docs/specs/world-identity.md)
     * @param  string  $terrariaVersion  version-pinned catalog の選択に使う (docs/design.md §2.3)
     * @param  array<array-key, mixed>  $flags  raw な world flag map (値は JSON boolean 想定)
     * @param  list<mixed>  $collectionChests  raw な Collection Chest の配列。
     *                                         各要素は `{x, y, name, items: [...]}` 形式の object を想定し、
     *                                         `items` の各要素は未検証の raw Item。
     * @param  string|null  $requestId  診断ログの相関 ID
     */
    public function __construct(
        public string $worldKey,
        public string $terrariaVersion,
        public array $flags,
        public array $collectionChests,
        public ?string $requestId = null,
    ) {}

    /**
     * envelope validation 済みの Snapshot 配列から組み立てる。
     *
     * `reason` は意図的に読まない。達成判定は reason (`periodic` など) に依存させない
     * (docs/design.md §6.3 / tasks/04-achievement-evaluation.md)。
     *
     * @param  array<array-key, mixed>  $snapshot
     */
    public static function fromValidatedSnapshot(array $snapshot): self
    {
        $world = $snapshot['world'] ?? null;
        $runtime = $snapshot['runtime'] ?? null;

        $worldKey = is_array($world) && is_string($world['key'] ?? null) ? $world['key'] : '';
        $terrariaVersion = is_array($runtime) && is_string($runtime['terrariaVersion'] ?? null)
            ? $runtime['terrariaVersion']
            : '';

        $flags = $snapshot['flags'] ?? [];
        $chests = $snapshot['collectionChests'] ?? [];
        $requestId = $snapshot['requestId'] ?? null;

        return new self(
            worldKey: $worldKey,
            terrariaVersion: $terrariaVersion,
            flags: is_array($flags) ? $flags : [],
            collectionChests: is_array($chests) ? array_values($chests) : [],
            requestId: is_string($requestId) ? $requestId : null,
        );
    }
}
