<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use App\Domain\Snapshot\ItemRejectionReason;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト
 * 「invalid Item 値が存在しても request-level validation だけを理由に全体 reject しない」。
 *
 * docs/design.md §6.4「Item 単位で無視する validation」/ §18.3、AC-17。
 * catalog 照合 (type の実在 / maxStack) は Task 04 が {@see ItemRejectionReason} の
 * 予約 case を使って追加する。
 */
final class SnapshotItemBoundaryTest extends SnapshotTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function payloadWithMixedItems(): array
    {
        return SnapshotPayload::with([
            'collectionChests' => [
                [
                    'x' => 120,
                    'y' => 340,
                    'name' => SnapshotPayload::CHEST_NAME,
                    'items' => [
                        ['type' => 1326, 'stack' => 1, 'name' => 'Rod of Discord'],  // 0: 有効
                        ['type' => '1326', 'stack' => 1],                            // 1: type が文字列
                        ['type' => 1326, 'stack' => '1'],                            // 2: stack が文字列
                        ['type' => 1326, 'stack' => 0],                              // 3: stack が 0
                        ['type' => 1326, 'stack' => -5],                             // 4: stack が負
                        ['stack' => 1],                                              // 5: type 欠落
                        ['type' => 1326],                                            // 6: stack 欠落
                        'Rod of Discord',                                            // 7: object でない
                        ['type' => 2, 'stack' => 999],                               // 8: 有効 (catalog 照合は Task 04)
                    ],
                ],
            ],
        ]);
    }

    #[Test]
    public function an_invalid_item_does_not_reject_the_whole_snapshot(): void
    {
        $processor = $this->recordProcessor();

        $response = $this->postSnapshot($this->payloadWithMixedItems());

        $response->assertOk();
        $response->assertJsonPath('requestId', SnapshotPayload::REQUEST_ID);
        $this->assertCount(1, $processor->received);
    }

    #[Test]
    public function the_snapshot_dto_carries_per_item_validation_results_to_the_next_layer(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot($this->payloadWithMixedItems())->assertOk();

        $chest = $processor->last()->collectionChests[0];

        $this->assertCount(9, $chest->items);
        $this->assertCount(2, $chest->acceptedItems(), 'only the two structurally valid items are candidates');
        $this->assertCount(7, $chest->rejectedItems());

        $this->assertSame([1326, 2], array_map(
            static fn ($item): int => $item->type(),
            $chest->acceptedItems(),
        ));

        $reasons = [];
        foreach ($chest->items as $item) {
            $reasons[$item->index] = array_map(
                static fn (ItemRejectionReason $reason): string => $reason->value,
                $item->rejections,
            );
        }

        $this->assertSame([], $reasons[0]);
        $this->assertSame(['type_not_integer'], $reasons[1]);
        $this->assertSame(['stack_not_integer'], $reasons[2]);
        $this->assertSame(['stack_not_positive'], $reasons[3]);
        $this->assertSame(['stack_not_positive'], $reasons[4]);
        $this->assertSame(['type_not_integer'], $reasons[5]);
        $this->assertSame(['stack_not_integer'], $reasons[6]);
        $this->assertSame(['not_an_object'], $reasons[7]);
        $this->assertSame([], $reasons[8]);
    }

    #[Test]
    public function world_flags_are_still_evaluated_when_every_item_is_invalid(): void
    {
        // docs/design.md §18.3: 不正 Item があっても Boss / World State の処理は継続する。
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with([
            'flags' => ['downedBoss1' => true, 'hardMode' => false],
            'collectionChests.0.items' => [
                ['type' => 'nope', 'stack' => 'nope'],
            ],
        ]))->assertOk();

        $snapshot = $processor->last();
        $this->assertTrue($snapshot->flags->isSet('downedBoss1'));
        $this->assertFalse($snapshot->flags->isSet('hardMode'));
        $this->assertSame([], $snapshot->collectionChests[0]->acceptedItems());
        $this->assertCount(1, $snapshot->rejectedItems());
    }

    #[Test]
    public function an_unset_flag_is_never_treated_as_achieved(): void
    {
        // AC-17: 再判定根拠がない進捗を推測して登録しない。
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['flags' => ['downedBoss1' => true]]))->assertOk();

        $flags = $processor->last()->flags;
        $this->assertFalse($flags->isSet('downedMoonlord'));
        $this->assertFalse($flags->has('downedMoonlord'));
    }
}
