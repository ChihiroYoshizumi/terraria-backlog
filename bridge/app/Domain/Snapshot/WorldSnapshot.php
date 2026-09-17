<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use DateTimeImmutable;

/**
 * request-level validation を通過した Snapshot (docs/design.md §6.2, §6.4)。
 *
 * このオブジェクトが存在する時点で以下は保証されている。
 *
 * - Adapter Bearer Token による認証済み (docs/design.md §16.1)
 * - `schemaVersion === 1`
 * - `requestId` が UUID
 * - `reason` が許可値
 * - runtime pair が `TERRARIA_SUPPORTED_RUNTIME` と一致 (docs/design.md §2.3)
 * - URL の worldKey・payload の `world.key`・allowlist の 3 つが一致 (§5.2)
 * - `collectionChestName` と各 chest の `name` が PHP 設定と一致
 * - コンテナ構造と件数上限を満たす
 * - 外部操作先を指定する禁止 field を含まない (§16.3)
 *
 * **保証していないもの:** 個々の Item 値の妥当性。Item は
 * {@see ObservedItem} として catalog 照合前の状態で保持され、
 * 構造不正な Item があっても Snapshot 全体は有効である (§18.3 / AC-17)。
 */
final readonly class WorldSnapshot
{
    /**
     * @param  list<CollectionChest>  $collectionChests
     * @param  list<string>  $triggerPlayerNames
     */
    public function __construct(
        public int $schemaVersion,
        public string $requestId,
        public SnapshotReason $reason,
        public DateTimeImmutable $observedAt,
        public bool $recoveryPending,
        public RuntimeVersions $runtime,
        public WorldIdentity $world,
        public string $collectionChestName,
        public WorldFlags $flags,
        public array $collectionChests,
        public array $triggerPlayerNames,
    ) {}

    public function worldKey(): WorldKey
    {
        return $this->world->key;
    }

    /**
     * 構造レベルで無効な Item を chest とあわせて列挙する (診断ログ用)。
     *
     * @return list<array{chest: CollectionChest, item: ObservedItem}>
     */
    public function rejectedItems(): array
    {
        $rejected = [];

        foreach ($this->collectionChests as $chest) {
            foreach ($chest->rejectedItems() as $item) {
                $rejected[] = ['chest' => $chest, 'item' => $item];
            }
        }

        return $rejected;
    }

    /**
     * 構造化ログ用の最小 context (docs/design.md §17)。
     * 秘密情報・不要な Player 情報は含めない (docs/spec.md §10)。
     *
     * @return array{request_id: string, world_key: string, reason: string}
     */
    public function logContext(): array
    {
        return [
            'request_id' => $this->requestId,
            'world_key' => $this->world->key->value,
            'reason' => $this->reason->value,
        ];
    }
}
