<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「件数上限超過を reject」。
 *
 * docs/design.md §6.4「Request Body / Chest / Item 件数が設定上限を超える」、
 * docs/spec.md §10「入力の型・サイズ…を検証する」。
 */
final class SnapshotLimitTest extends SnapshotTestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function chests(int $count, int $itemsPerChest = 1): array
    {
        $chests = [];

        for ($i = 0; $i < $count; $i++) {
            $items = [];

            for ($j = 0; $j < $itemsPerChest; $j++) {
                $items[] = ['type' => 1326, 'stack' => 1];
            }

            $chests[] = [
                'x' => $i,
                'y' => $i,
                'name' => SnapshotPayload::CHEST_NAME,
                'items' => $items,
            ];
        }

        return $chests;
    }

    #[Test]
    public function it_rejects_more_chests_than_the_configured_limit(): void
    {
        config()->set('terraria.limits.chests', 3);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(3)]))->assertOk();

        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(4)]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function it_rejects_more_items_in_one_chest_than_the_configured_limit(): void
    {
        config()->set('terraria.limits.items_per_chest', 2);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(1, 2)]))->assertOk();

        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(1, 3)]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function it_rejects_more_items_in_total_than_the_configured_limit(): void
    {
        config()->set('terraria.limits.chests', 10);
        config()->set('terraria.limits.items_per_chest', 5);
        config()->set('terraria.limits.items_total', 6);
        $processor = $this->recordProcessor();

        // chest ごとの上限は満たすが、合計で超える組み合わせを弾くこと。
        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(2, 3)]))->assertOk();

        $this->postSnapshot(SnapshotPayload::with(['collectionChests' => $this->chests(3, 3)]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function it_rejects_more_flags_than_the_configured_limit(): void
    {
        config()->set('terraria.limits.flags', 4);
        $processor = $this->recordProcessor();

        $flags = [];
        for ($i = 0; $i < 5; $i++) {
            $flags['downedBoss'.$i] = true;
        }

        $this->postSnapshot(SnapshotPayload::with(['flags' => $flags]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_more_trigger_player_names_than_the_configured_limit(): void
    {
        config()->set('terraria.limits.trigger_player_names', 2);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with([
            'trigger.playerNames' => ['p1', 'p2', 'p3'],
        ]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_a_request_body_larger_than_the_configured_limit(): void
    {
        $processor = $this->recordProcessor();

        $body = (string) json_encode(SnapshotPayload::valid());
        config()->set('terraria.limits.request_body_bytes', strlen($body) - 1);

        $this->postSnapshotRaw($body)
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'request.body_too_large');

        config()->set('terraria.limits.request_body_bytes', strlen($body));
        $this->postSnapshotRaw($body)->assertOk();

        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function it_rejects_over_long_strings(): void
    {
        // world.key (18 文字) は通り、上書きした値だけが上限を超える長さにする。
        config()->set('terraria.limits.string_length', 24);
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::valid())->assertOk();

        $this->postSnapshot(SnapshotPayload::with(['world.name' => str_repeat('a', 25)]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->postSnapshot(SnapshotPayload::with(['trigger.playerNames' => [str_repeat('p', 25)]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'limit.exceeded');

        $this->assertCount(1, $processor->received);
    }
}
