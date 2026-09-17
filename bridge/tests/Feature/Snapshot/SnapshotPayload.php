<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

/**
 * contracts/examples/snapshot-v1.json と同じ形の有効な Snapshot payload を組み立てる
 * テスト用ビルダー。テストは「壊したい 1 箇所」だけを上書きする。
 */
final class SnapshotPayload
{
    public const WORLD_KEY = 'terraria:123456789';

    public const REQUEST_ID = '0199f136-9e36-7f41-b148-e5b4f384a321';

    public const CHEST_NAME = 'BACKLOG_COLLECTION';

    /**
     * @return array<string, mixed>
     */
    public static function valid(): array
    {
        return [
            'schemaVersion' => 1,
            'requestId' => self::REQUEST_ID,
            'reason' => 'collection_change',
            'observedAt' => '2026-09-16T10:00:00+09:00',
            'runtime' => [
                'adapterVersion' => '0.1.0',
                'tshockVersion' => '4.3.13',
                'terrariaVersion' => '1.3.0.8',
            ],
            'world' => [
                'key' => self::WORLD_KEY,
                'terrariaWorldId' => 123456789,
                'name' => 'Fusic World',
            ],
            'collectionChestName' => self::CHEST_NAME,
            'flags' => [
                'downedBoss1' => true,
                'hardMode' => false,
            ],
            'collectionChests' => [
                [
                    'x' => 120,
                    'y' => 340,
                    'name' => self::CHEST_NAME,
                    'items' => [
                        ['type' => 1326, 'stack' => 1, 'name' => 'Rod of Discord'],
                    ],
                ],
            ],
            'trigger' => [
                'playerNames' => ['player1'],
            ],
        ];
    }

    /**
     * ドット記法で 1 箇所だけ差し替える。値に null を渡すとそのキーを削除する。
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function with(array $overrides): array
    {
        $payload = self::valid();

        foreach ($overrides as $path => $value) {
            $payload = self::apply($payload, explode('.', $path), $value);
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $target
     * @param  list<string>  $segments
     * @return array<array-key, mixed>
     */
    private static function apply(array $target, array $segments, mixed $value): array
    {
        $head = array_shift($segments);
        $key = ctype_digit($head) ? (int) $head : $head;

        if ($segments === []) {
            if ($value === null) {
                unset($target[$key]);
            } else {
                $target[$key] = $value;
            }

            return $target;
        }

        /** @var array<array-key, mixed> $child */
        $child = is_array($target[$key] ?? null) ? $target[$key] : [];
        $target[$key] = self::apply($child, $segments, $value);

        return $target;
    }
}
