<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「world URL/payload mismatch を reject」「allowlist 外 world を reject」。
 *
 * AC-13 / AC-16, docs/design.md §5.2, docs/specs/world-identity.md。
 */
final class SnapshotWorldValidationTest extends SnapshotTestCase
{
    #[Test]
    public function it_rejects_a_snapshot_whose_payload_world_key_differs_from_the_url(): void
    {
        config()->set('terraria.allowed_world_keys', [
            SnapshotPayload::WORLD_KEY,
            'terraria:987654321',
        ]);
        $processor = $this->recordProcessor();

        // URL も payload も単体では allowlist に載っている。一致しないことだけが問題。
        $response = $this->postSnapshot(
            SnapshotPayload::with(['world.key' => 'terraria:987654321']),
            worldKey: SnapshotPayload::WORLD_KEY,
        );

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'world.key_mismatch');

        // 片方のキーへ読み替えて処理を続けない (docs/specs/world-identity.md)。
        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_a_world_key_outside_the_allowlist(): void
    {
        config()->set('terraria.allowed_world_keys', ['terraria:999999999']);
        $processor = $this->recordProcessor();

        // URL と payload は一致している。allowlist 外であることだけが問題。
        $response = $this->postSnapshot(SnapshotPayload::valid());

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'world.not_allowed');
        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_every_world_when_the_allowlist_is_empty(): void
    {
        config()->set('terraria.allowed_world_keys', []);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::valid())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'bridge.misconfigured');

        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_a_non_integer_terraria_world_id(): void
    {
        $processor = $this->recordProcessor();

        // 文字列を integer へ coercion しない (docs/design.md §6.4)。
        $this->postSnapshot(SnapshotPayload::with(['world.terrariaWorldId' => '123456789']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'world.invalid_terraria_world_id');

        $this->postSnapshotRaw((string) json_encode(
            SnapshotPayload::with(['world.terrariaWorldId' => 0]),
        ))->assertOk();

        // 浮動小数点は integer ではない。
        $body = str_replace('"terrariaWorldId":123456789', '"terrariaWorldId":123456789.0', (string) json_encode(SnapshotPayload::valid()));
        $this->postSnapshotRaw($body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'world.invalid_terraria_world_id');

        $this->assertCount(1, $processor->received, 'only the valid world id should reach the application layer');
    }

    #[Test]
    public function it_rejects_a_world_container_that_is_not_an_object(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['world' => 'terraria:123456789']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'world.invalid');

        $this->assertSame([], $processor->received);
    }
}
