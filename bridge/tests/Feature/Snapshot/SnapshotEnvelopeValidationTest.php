<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「malformed envelope を reject」。
 *
 * docs/design.md §6.4「リクエスト全体を拒否する validation」。
 * 型 coercion は行わない。
 */
final class SnapshotEnvelopeValidationTest extends SnapshotTestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function malformedBodies(): array
    {
        return [
            'empty body' => ['', 400, 'request.malformed_json'],
            'truncated json' => ['{"schemaVersion": 1', 400, 'request.malformed_json'],
            'not json at all' => ['schemaVersion=1', 400, 'request.malformed_json'],
            'json array at top level' => ['[]', 422, 'envelope.not_an_object'],
            'json string at top level' => ['"snapshot"', 422, 'envelope.not_an_object'],
            'json null at top level' => ['null', 422, 'envelope.not_an_object'],
            'json number at top level' => ['1', 422, 'envelope.not_an_object'],
        ];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function it_rejects_malformed_request_bodies(string $body, int $status, string $code): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshotRaw($body)
            ->assertStatus($status)
            ->assertJsonPath('error.code', $code);

        $this->assertSame([], $processor->received);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidEnvelopeFields(): array
    {
        return [
            'schemaVersion 2' => [['schemaVersion' => 2], 'envelope.unsupported_schema_version'],
            'schemaVersion as string' => [['schemaVersion' => '1'], 'envelope.unsupported_schema_version'],
            'schemaVersion missing' => [['schemaVersion' => null], 'envelope.unsupported_schema_version'],
            'requestId not a uuid' => [['requestId' => 'not-a-uuid'], 'envelope.invalid_request_id'],
            'requestId empty' => [['requestId' => ''], 'envelope.invalid_request_id'],
            'requestId missing' => [['requestId' => null], 'envelope.invalid_request_id'],
            'requestId with sql-ish payload' => [['requestId' => "' OR 1=1 --"], 'envelope.invalid_request_id'],
            'reason unknown' => [['reason' => 'boss_defeated'], 'envelope.invalid_reason'],
            'reason missing' => [['reason' => null], 'envelope.invalid_reason'],
            'reason not a string' => [['reason' => 1], 'envelope.invalid_reason'],
            'observedAt not a date' => [['observedAt' => 'yesterday'], 'envelope.invalid_observed_at'],
            'observedAt without offset' => [['observedAt' => '2026-09-16T10:00:00'], 'envelope.invalid_observed_at'],
            'observedAt missing' => [['observedAt' => null], 'envelope.invalid_observed_at'],
            // DateTimeImmutable は例外を投げず 2026-03-02 へ繰り上げ正規化してしまう。
            'observedAt on a day that does not exist' => [['observedAt' => '2026-02-30T10:00:00+00:00'], 'envelope.invalid_observed_at'],
            'observedAt on a non-leap Feb 29' => [['observedAt' => '2025-02-29T10:00:00+09:00'], 'envelope.invalid_observed_at'],
            'collectionChestName mismatch' => [['collectionChestName' => 'OTHER_CHEST'], 'collection_chest.name_mismatch'],
            'collectionChestName missing' => [['collectionChestName' => null], 'collection_chest.name_mismatch'],
            'chest name mismatch' => [['collectionChests.0.name' => 'OTHER_CHEST'], 'collection_chest.name_mismatch'],
            'collectionChests not an array' => [['collectionChests' => ['a' => 1]], 'collection_chest.invalid_container'],
            'collectionChests missing' => [['collectionChests' => null], 'collection_chest.invalid_container'],
            'chest entry not an object' => [['collectionChests.0' => 'chest'], 'collection_chest.invalid_container'],
            'chest coordinates not integers' => [['collectionChests.0.x' => '120'], 'collection_chest.invalid_container'],
            'chest items not an array' => [['collectionChests.0.items' => 'none'], 'collection_chest.invalid_container'],
            'flags not an object' => [['flags' => ['downedBoss1']], 'flags.invalid'],
            'flags missing' => [['flags' => null], 'flags.invalid'],
            'flag value not a boolean' => [['flags.hardMode' => 'true'], 'flags.invalid'],
            'flag value numeric' => [['flags.hardMode' => 1], 'flags.invalid'],
            'trigger not an object' => [['trigger' => 'player1'], 'trigger.invalid'],
            'trigger playerNames not an array' => [['trigger.playerNames' => 'player1'], 'trigger.invalid'],
            'trigger playerName not a string' => [['trigger.playerNames' => [42]], 'trigger.invalid'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('invalidEnvelopeFields')]
    public function it_rejects_a_structurally_invalid_envelope(array $overrides, string $code): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with($overrides))
            ->assertStatus(422)
            ->assertJsonPath('error.code', $code);

        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_rejects_a_non_boolean_recovery_pending_but_allows_it_to_be_omitted(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['recoveryPending' => 'true']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'envelope.invalid_recovery_pending');

        $this->postSnapshot(SnapshotPayload::with(['recoveryPending' => 1]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'envelope.invalid_recovery_pending');

        // 省略時は false (docs/design.md §22.3)。
        $this->postSnapshot(SnapshotPayload::valid())->assertOk();
        $this->assertFalse($processor->last()->recoveryPending);

        $this->postSnapshot(SnapshotPayload::with(['recoveryPending' => true]))->assertOk();
        $this->assertTrue($processor->last()->recoveryPending);
    }

    #[Test]
    public function it_accepts_unknown_top_level_fields_for_forward_compatibility(): void
    {
        // docs/design.md §6.4: 未知の top-level field は読み飛ばしてよい。
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with([
            'someFutureField' => ['anything' => true],
        ]))->assertOk();

        $this->assertCount(1, $processor->received);
    }
}
