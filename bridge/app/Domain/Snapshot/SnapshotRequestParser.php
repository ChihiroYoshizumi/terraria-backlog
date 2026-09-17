<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use DateTimeImmutable;
use Exception;
use JsonException;
use stdClass;

/**
 * Adapter → PHP Snapshot request の request-level validation と DTO 化
 * (docs/design.md §6.2 / §6.4, contracts/snapshot-v1.schema.json)。
 *
 * 設計上の約束:
 *
 * 1. **型 coercion を行わない。** JSON を `assoc = false` で decode し、
 *    JSON object (stdClass) と JSON array (list) を区別する。`"1"` は integer でなく
 *    `1.0` も integer でない。
 * 2. **request-level と item-level を混ぜない。** ここで reject するのは
 *    envelope / world / runtime / container / 件数上限 / 禁止 field のみ。
 *    個々の Item 値の不正は {@see ObservedItem} に記録して持ち越し、
 *    Snapshot 全体は成立させる (docs/design.md §18.3, AC-17)。
 * 3. **外部操作先は PHP 設定からのみ取得する** (docs/design.md §16.3)。
 *    payload 内に禁止 field が現れた時点で Snapshot 全体を拒否する。
 *
 * 検証順序は docs/design.md §6.4 の箇条書き順に合わせてある。
 */
final readonly class SnapshotRequestParser
{
    /**
     * RFC 4122 の UUID。version (1-8) と variant (8/9/a/b) まで確認する。
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * JSON Schema の `format: date-time` 相当。offset 付きの ISO 8601 のみ受理する。
     */
    private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$/';

    /**
     * Terraria の `Terraria.NPC` / `Terraria.Main` フィールド名に対応する安全な範囲。
     */
    private const FLAG_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';

    public function __construct(private SnapshotValidationSettings $settings) {}

    /**
     * @param  string  $urlWorldKey  URL path の {worldKey}
     * @param  string  $rawBody  受信した request body そのもの
     *
     * @throws SnapshotRejectedException Snapshot 全体を拒否する場合
     */
    public function parse(string $urlWorldKey, string $rawBody): WorldSnapshot
    {
        $this->assertBridgeConfigured();

        $payload = $this->decode($rawBody);

        $schemaVersion = $this->parseSchemaVersion($payload);
        $requestId = $this->parseRequestId($payload);
        $reason = $this->parseReason($payload);
        $observedAt = $this->parseObservedAt($payload);
        $recoveryPending = $this->parseRecoveryPending($payload);
        $runtime = $this->parseRuntime($payload);
        $world = $this->parseWorld($payload, $urlWorldKey);
        $collectionChestName = $this->parseCollectionChestName($payload);
        $flags = $this->parseFlags($payload);
        $chests = $this->parseCollectionChests($payload);
        $playerNames = $this->parseTriggerPlayerNames($payload);

        // docs/design.md §16.3: 外部操作先を指定しようとする入力は最後に全階層を走査する。
        $this->assertNoForbiddenFields($payload);

        return new WorldSnapshot(
            schemaVersion: $schemaVersion,
            requestId: $requestId,
            reason: $reason,
            observedAt: $observedAt,
            recoveryPending: $recoveryPending,
            runtime: $runtime,
            world: $world,
            collectionChestName: $collectionChestName,
            flags: $flags,
            collectionChests: $chests,
            triggerPlayerNames: $playerNames,
        );
    }

    // ------------------------------------------------------------------
    // 設定
    // ------------------------------------------------------------------

    private function assertBridgeConfigured(): void
    {
        if ($this->settings->supportedRuntime === '') {
            throw $this->reject(
                SnapshotRejectionCode::BridgeMisconfigured,
                'TERRARIA_SUPPORTED_RUNTIME is not configured.',
            );
        }

        if ($this->settings->collectionChestName === '') {
            throw $this->reject(
                SnapshotRejectionCode::BridgeMisconfigured,
                'TERRARIA_COLLECTION_CHEST_NAME is not configured.',
            );
        }

        if ($this->settings->allowedWorldKeys === []) {
            throw $this->reject(
                SnapshotRejectionCode::BridgeMisconfigured,
                'TERRARIA_ALLOWED_WORLD_KEYS is not configured.',
            );
        }
    }

    // ------------------------------------------------------------------
    // envelope
    // ------------------------------------------------------------------

    private function decode(string $rawBody): stdClass
    {
        $length = strlen($rawBody);

        if ($length > $this->settings->maxRequestBodyBytes) {
            throw $this->reject(
                SnapshotRejectionCode::BodyTooLarge,
                sprintf(
                    'request body is %d bytes, limit is %d bytes.',
                    $length,
                    $this->settings->maxRequestBodyBytes,
                ),
            );
        }

        try {
            $decoded = json_decode($rawBody, false, max(2, $this->settings->maxJsonDepth), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->reject(SnapshotRejectionCode::MalformedJson, 'request body is not valid JSON.');
        }

        if (! $decoded instanceof stdClass) {
            throw $this->reject(
                SnapshotRejectionCode::EnvelopeNotObject,
                'snapshot envelope must be a JSON object.',
            );
        }

        return $decoded;
    }

    private function parseSchemaVersion(stdClass $payload): int
    {
        $value = $payload->schemaVersion ?? null;

        if ($value !== 1) {
            throw $this->reject(
                SnapshotRejectionCode::UnsupportedSchemaVersion,
                'schemaVersion must be the integer 1.',
            );
        }

        return 1;
    }

    private function parseRequestId(stdClass $payload): string
    {
        $value = $payload->requestId ?? null;

        if (! is_string($value) || preg_match(self::UUID_PATTERN, $value) !== 1) {
            throw $this->reject(SnapshotRejectionCode::InvalidRequestId, 'requestId must be a UUID string.');
        }

        return $value;
    }

    private function parseReason(stdClass $payload): SnapshotReason
    {
        $value = $payload->reason ?? null;

        $reason = is_string($value) ? SnapshotReason::tryFrom($value) : null;

        if ($reason === null) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidReason,
                'reason must be one of startup, periodic, collection_change, world_change, manual.',
            );
        }

        return $reason;
    }

    private function parseObservedAt(stdClass $payload): DateTimeImmutable
    {
        $value = $payload->observedAt ?? null;

        if (! is_string($value) || preg_match(self::DATE_TIME_PATTERN, $value) !== 1) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidObservedAt,
                'observedAt must be an ISO 8601 date-time with a UTC offset.',
            );
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw $this->reject(SnapshotRejectionCode::InvalidObservedAt, 'observedAt is not a real date-time.');
        }
    }

    private function parseRecoveryPending(stdClass $payload): bool
    {
        if (! property_exists($payload, 'recoveryPending')) {
            // docs/design.md §22.3: 省略可能な boolean。省略時は false。
            return false;
        }

        $value = $payload->recoveryPending;

        if (! is_bool($value)) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidRecoveryPending,
                'recoveryPending must be a JSON boolean when present.',
            );
        }

        return $value;
    }

    // ------------------------------------------------------------------
    // runtime (docs/design.md §2.3)
    // ------------------------------------------------------------------

    private function parseRuntime(stdClass $payload): RuntimeVersions
    {
        $runtime = $payload->runtime ?? null;

        if (! $runtime instanceof stdClass) {
            throw $this->reject(SnapshotRejectionCode::InvalidRuntime, 'runtime must be a JSON object.');
        }

        $versions = new RuntimeVersions(
            adapterVersion: $this->requiredString($runtime, 'adapterVersion', SnapshotRejectionCode::InvalidRuntime, 'runtime.adapterVersion'),
            tshockVersion: $this->requiredString($runtime, 'tshockVersion', SnapshotRejectionCode::InvalidRuntime, 'runtime.tshockVersion'),
            terrariaVersion: $this->requiredString($runtime, 'terrariaVersion', SnapshotRejectionCode::InvalidRuntime, 'runtime.terrariaVersion'),
        );

        if (! $versions->matches($this->settings->supportedRuntime)) {
            throw $this->reject(
                SnapshotRejectionCode::UnsupportedRuntime,
                'runtime pair does not match TERRARIA_SUPPORTED_RUNTIME.',
            );
        }

        return $versions;
    }

    // ------------------------------------------------------------------
    // world (docs/design.md §5.2)
    // ------------------------------------------------------------------

    private function parseWorld(stdClass $payload, string $urlWorldKey): WorldIdentity
    {
        $world = $payload->world ?? null;

        if (! $world instanceof stdClass) {
            throw $this->reject(SnapshotRejectionCode::InvalidWorld, 'world must be a JSON object.');
        }

        $rawKey = $this->requiredString($world, 'key', SnapshotRejectionCode::InvalidWorld, 'world.key');

        try {
            $key = WorldKey::fromString($rawKey);
            $fromUrl = WorldKey::fromString($urlWorldKey);
        } catch (Exception) {
            throw $this->reject(SnapshotRejectionCode::InvalidWorld, 'world key is not a usable identifier.');
        }

        if (! $key->equals($fromUrl)) {
            throw $this->reject(
                SnapshotRejectionCode::WorldKeyMismatch,
                'world.key does not match the worldKey in the request URL.',
            );
        }

        if (! $this->settings->isWorldAllowed($key)) {
            throw $this->reject(
                SnapshotRejectionCode::WorldNotAllowed,
                'world key is not present in TERRARIA_ALLOWED_WORLD_KEYS.',
            );
        }

        $worldId = $world->terrariaWorldId ?? null;

        if (! is_int($worldId)) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidTerrariaWorldId,
                'world.terrariaWorldId must be a JSON integer.',
            );
        }

        $name = null;

        if (property_exists($world, 'name')) {
            if (! is_string($world->name)) {
                throw $this->reject(SnapshotRejectionCode::InvalidWorld, 'world.name must be a string when present.');
            }

            $name = $this->boundedString($world->name, SnapshotRejectionCode::InvalidWorld, 'world.name');
        }

        return new WorldIdentity($key, $worldId, $name);
    }

    // ------------------------------------------------------------------
    // Collection Chest (docs/spec.md §6)
    // ------------------------------------------------------------------

    private function parseCollectionChestName(stdClass $payload): string
    {
        $value = $payload->collectionChestName ?? null;

        if (! is_string($value) || ! hash_equals($this->settings->collectionChestName, $value)) {
            throw $this->reject(
                SnapshotRejectionCode::CollectionChestNameMismatch,
                'collectionChestName does not match TERRARIA_COLLECTION_CHEST_NAME.',
            );
        }

        return $this->settings->collectionChestName;
    }

    /**
     * @return list<CollectionChest>
     */
    private function parseCollectionChests(stdClass $payload): array
    {
        $chests = $payload->collectionChests ?? null;

        if (! $this->isJsonArray($chests)) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidCollectionChests,
                'collectionChests must be a JSON array.',
            );
        }

        if (count($chests) > $this->settings->maxChests) {
            throw $this->reject(
                SnapshotRejectionCode::LimitExceeded,
                sprintf('collectionChests has %d entries, limit is %d.', count($chests), $this->settings->maxChests),
            );
        }

        $parsed = [];
        $totalItems = 0;

        foreach ($chests as $chest) {
            if (! $chest instanceof stdClass) {
                throw $this->reject(
                    SnapshotRejectionCode::InvalidCollectionChests,
                    'each collectionChests entry must be a JSON object.',
                );
            }

            $x = $chest->x ?? null;
            $y = $chest->y ?? null;

            if (! is_int($x) || ! is_int($y)) {
                throw $this->reject(
                    SnapshotRejectionCode::InvalidCollectionChests,
                    'collectionChests[].x and collectionChests[].y must be JSON integers.',
                );
            }

            $name = $chest->name ?? null;

            if (! is_string($name) || ! hash_equals($this->settings->collectionChestName, $name)) {
                throw $this->reject(
                    SnapshotRejectionCode::CollectionChestNameMismatch,
                    'collectionChests[].name does not match TERRARIA_COLLECTION_CHEST_NAME.',
                );
            }

            $items = $chest->items ?? null;

            if (! $this->isJsonArray($items)) {
                throw $this->reject(
                    SnapshotRejectionCode::InvalidCollectionChests,
                    'collectionChests[].items must be a JSON array.',
                );
            }

            if (count($items) > $this->settings->maxItemsPerChest) {
                throw $this->reject(
                    SnapshotRejectionCode::LimitExceeded,
                    sprintf(
                        'a chest carries %d items, limit is %d.',
                        count($items),
                        $this->settings->maxItemsPerChest,
                    ),
                );
            }

            $totalItems += count($items);

            if ($totalItems > $this->settings->maxItemsTotal) {
                throw $this->reject(
                    SnapshotRejectionCode::LimitExceeded,
                    sprintf('snapshot carries more than %d items in total.', $this->settings->maxItemsTotal),
                );
            }

            $parsed[] = new CollectionChest($x, $y, $this->settings->collectionChestName, $this->parseItems($items));
        }

        return $parsed;
    }

    /**
     * Item-level validation。**ここでは例外を投げない** (docs/design.md §6.4 / §18.3)。
     *
     * @param  list<mixed>  $items
     * @return list<ObservedItem>
     */
    private function parseItems(array $items): array
    {
        $observed = [];

        foreach (array_values($items) as $index => $item) {
            if (! $item instanceof stdClass) {
                $observed[] = new ObservedItem($index, null, null, null, [ItemRejectionReason::NotAnObject]);

                continue;
            }

            $rawType = $item->type ?? null;
            $rawStack = $item->stack ?? null;
            $rejections = [];

            if (! is_int($rawType)) {
                $rejections[] = ItemRejectionReason::TypeNotInteger;
            }

            if (! is_int($rawStack)) {
                $rejections[] = ItemRejectionReason::StackNotInteger;
            } elseif ($rawStack < 1) {
                $rejections[] = ItemRejectionReason::StackNotPositive;
            }

            $name = null;

            if (is_string($item->name ?? null) && strlen($item->name) <= $this->settings->maxStringLength) {
                $name = $item->name;
            }

            $observed[] = new ObservedItem($index, $rawType, $rawStack, $name, $rejections);
        }

        return $observed;
    }

    // ------------------------------------------------------------------
    // flags / trigger
    // ------------------------------------------------------------------

    private function parseFlags(stdClass $payload): WorldFlags
    {
        $flags = $payload->flags ?? null;

        if (! $flags instanceof stdClass) {
            throw $this->reject(SnapshotRejectionCode::InvalidFlags, 'flags must be a JSON object.');
        }

        $values = get_object_vars($flags);

        if (count($values) > $this->settings->maxFlags) {
            throw $this->reject(
                SnapshotRejectionCode::LimitExceeded,
                sprintf('flags has %d entries, limit is %d.', count($values), $this->settings->maxFlags),
            );
        }

        $parsed = [];

        foreach ($values as $name => $value) {
            $name = (string) $name;

            if (preg_match(self::FLAG_NAME_PATTERN, $name) !== 1) {
                throw $this->reject(SnapshotRejectionCode::InvalidFlags, 'flags contains an unusable flag name.');
            }

            if (! is_bool($value)) {
                throw $this->reject(SnapshotRejectionCode::InvalidFlags, 'flags values must be JSON booleans.');
            }

            $parsed[$name] = $value;
        }

        return new WorldFlags($parsed);
    }

    /**
     * @return list<string>
     */
    private function parseTriggerPlayerNames(stdClass $payload): array
    {
        if (! property_exists($payload, 'trigger')) {
            return [];
        }

        $trigger = $payload->trigger;

        if (! $trigger instanceof stdClass) {
            throw $this->reject(SnapshotRejectionCode::InvalidTrigger, 'trigger must be a JSON object when present.');
        }

        if (! property_exists($trigger, 'playerNames')) {
            return [];
        }

        $names = $trigger->playerNames;

        if (! $this->isJsonArray($names)) {
            throw $this->reject(
                SnapshotRejectionCode::InvalidTrigger,
                'trigger.playerNames must be a JSON array of strings.',
            );
        }

        if (count($names) > $this->settings->maxTriggerPlayerNames) {
            throw $this->reject(
                SnapshotRejectionCode::LimitExceeded,
                sprintf(
                    'trigger.playerNames has %d entries, limit is %d.',
                    count($names),
                    $this->settings->maxTriggerPlayerNames,
                ),
            );
        }

        $parsed = [];

        foreach ($names as $name) {
            if (! is_string($name) || $name === '') {
                throw $this->reject(
                    SnapshotRejectionCode::InvalidTrigger,
                    'trigger.playerNames must contain non-empty strings.',
                );
            }

            $parsed[] = $this->boundedString($name, SnapshotRejectionCode::InvalidTrigger, 'trigger.playerNames[]');
        }

        return $parsed;
    }

    // ------------------------------------------------------------------
    // 禁止 field (docs/design.md §16.3)
    // ------------------------------------------------------------------

    private function assertNoForbiddenFields(mixed $node, string $path = ''): void
    {
        if ($node instanceof stdClass) {
            foreach (get_object_vars($node) as $key => $value) {
                $key = (string) $key;
                $childPath = $path === '' ? $key : $path.'.'.$key;

                if ($this->settings->isForbiddenKey($key)) {
                    throw $this->reject(
                        SnapshotRejectionCode::ForbiddenField,
                        sprintf(
                            'payload must not specify an external write target (%s). '
                            .'URL / Backlog Space / Project Key / Issue Key / Custom Field ID / Status ID '
                            .'are taken from PHP configuration only.',
                            $childPath,
                        ),
                    );
                }

                $this->assertNoForbiddenFields($value, $childPath);
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $index => $value) {
                $this->assertNoForbiddenFields($value, $path.'['.$index.']');
            }
        }
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * @phpstan-assert-if-true list<mixed> $value
     */
    private function isJsonArray(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private function requiredString(
        stdClass $object,
        string $property,
        SnapshotRejectionCode $code,
        string $path,
    ): string {
        $value = $object->{$property} ?? null;

        if (! is_string($value) || $value === '') {
            throw $this->reject($code, sprintf('%s must be a non-empty string.', $path));
        }

        return $this->boundedString($value, $code, $path);
    }

    private function boundedString(string $value, SnapshotRejectionCode $code, string $path): string
    {
        if (strlen($value) > $this->settings->maxStringLength) {
            throw $this->reject(
                SnapshotRejectionCode::LimitExceeded,
                sprintf('%s exceeds the configured string length limit.', $path),
            );
        }

        return $value;
    }

    private function reject(SnapshotRejectionCode $code, string $detail): SnapshotRejectedException
    {
        return SnapshotRejectedException::because($code, $detail);
    }
}
