<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

/**
 * request-level validation が参照する PHP 側設定 (config/terraria.php)。
 *
 * Adapter payload の値でこれらを上書きしない (docs/design.md §16.3)。
 */
final readonly class SnapshotValidationSettings
{
    /**
     * @param  list<string>  $allowedWorldKeys
     * @param  list<string>  $forbiddenPayloadKeys  正規化済み (小文字・英数字のみ) のキー名
     */
    public function __construct(
        public array $allowedWorldKeys,
        public string $supportedRuntime,
        public string $collectionChestName,
        public int $maxRequestBodyBytes,
        public int $maxJsonDepth,
        public int $maxChests,
        public int $maxItemsPerChest,
        public int $maxItemsTotal,
        public int $maxFlags,
        public int $maxTriggerPlayerNames,
        public int $maxStringLength,
        public array $forbiddenPayloadKeys,
    ) {}

    /**
     * @param  array<string, mixed>  $config  config('terraria') の内容
     */
    public static function fromConfig(array $config): self
    {
        /** @var array<string, mixed> $limits */
        $limits = is_array($config['limits'] ?? null) ? $config['limits'] : [];

        /** @var list<string> $allowed */
        $allowed = array_values(array_filter(
            is_array($config['allowed_world_keys'] ?? null) ? $config['allowed_world_keys'] : [],
            'is_string',
        ));

        /** @var list<string> $forbidden */
        $forbidden = array_values(array_filter(
            is_array($config['forbidden_payload_keys'] ?? null) ? $config['forbidden_payload_keys'] : [],
            'is_string',
        ));

        return new self(
            allowedWorldKeys: $allowed,
            supportedRuntime: (string) ($config['supported_runtime'] ?? ''),
            collectionChestName: (string) ($config['collection_chest_name'] ?? ''),
            maxRequestBodyBytes: (int) ($limits['request_body_bytes'] ?? 1_048_576),
            maxJsonDepth: (int) ($limits['json_depth'] ?? 32),
            maxChests: (int) ($limits['chests'] ?? 64),
            maxItemsPerChest: (int) ($limits['items_per_chest'] ?? 40),
            maxItemsTotal: (int) ($limits['items_total'] ?? 2_560),
            maxFlags: (int) ($limits['flags'] ?? 256),
            maxTriggerPlayerNames: (int) ($limits['trigger_player_names'] ?? 32),
            maxStringLength: (int) ($limits['string_length'] ?? 512),
            forbiddenPayloadKeys: array_map(self::normalizeKey(...), $forbidden),
        );
    }

    public function isWorldAllowed(WorldKey $key): bool
    {
        return in_array($key->value, $this->allowedWorldKeys, true);
    }

    public function isForbiddenKey(string $key): bool
    {
        return in_array(self::normalizeKey($key), $this->forbiddenPayloadKeys, true);
    }

    /**
     * `project_key` / `projectKey` / `Project-Key` をすべて `projectkey` に揃える。
     */
    public static function normalizeKey(string $key): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));
    }
}
