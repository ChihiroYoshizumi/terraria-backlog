<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use App\Domain\Snapshot\SnapshotProcessor;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Snapshot endpoint の Feature Test 共通土台。
 *
 * 永続 DB を使わない (docs/design.md §2.1)。設定はテストごとに config で与える。
 */
abstract class SnapshotTestCase extends TestCase
{
    protected const TOKEN = 'test-adapter-token';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('terraria.adapter_token', self::TOKEN);
        config()->set('terraria.allowed_world_keys', [SnapshotPayload::WORLD_KEY]);
        config()->set('terraria.supported_runtime', '4.3.13:1.3.0.8');
        config()->set('terraria.collection_chest_name', SnapshotPayload::CHEST_NAME);
    }

    protected function recordProcessor(): RecordingSnapshotProcessor
    {
        $processor = new RecordingSnapshotProcessor;
        $this->app->instance(SnapshotProcessor::class, $processor);

        return $processor;
    }

    protected function url(string $worldKey = SnapshotPayload::WORLD_KEY): string
    {
        return '/api/v1/worlds/'.$worldKey.'/snapshots';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function postSnapshot(
        array $payload,
        string $worldKey = SnapshotPayload::WORLD_KEY,
        array $headers = [],
    ): TestResponse {
        return $this->postSnapshotRaw(
            (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $worldKey,
            $headers,
        );
    }

    /**
     * request body を 1 バイトも加工せずに送る。
     * `1.0` のような「integer ではない JSON number」を再現するために必要。
     *
     * @param  array<string, string|null>  $headers
     */
    protected function postSnapshotRaw(
        string $body,
        string $worldKey = SnapshotPayload::WORLD_KEY,
        array $headers = [],
    ): TestResponse {
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.self::TOKEN,
        ], $headers);

        $headers = array_filter($headers, static fn (?string $value): bool => $value !== null);

        return $this->call(
            'POST',
            $this->url($worldKey),
            [],
            [],
            [],
            $this->transformHeadersToServerVars($headers),
            $body,
        );
    }
}
