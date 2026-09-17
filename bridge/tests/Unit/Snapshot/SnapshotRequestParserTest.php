<?php

declare(strict_types=1);

namespace Tests\Unit\Snapshot;

use App\Domain\Snapshot\ItemRejectionReason;
use App\Domain\Snapshot\SnapshotReason;
use App\Domain\Snapshot\SnapshotRejectedException;
use App\Domain\Snapshot\SnapshotRejectionCode;
use App\Domain\Snapshot\SnapshotRequestParser;
use App\Domain\Snapshot\SnapshotValidationSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * request-level validation の単体テスト (docs/design.md §6.4)。
 *
 * Laravel を起動せずに検証できることで、この境界が framework の
 * validation rule (型 coercion あり) に依存していないことも示す。
 */
final class SnapshotRequestParserTest extends TestCase
{
    private const WORLD_KEY = 'terraria:123456789';

    private function parser(): SnapshotRequestParser
    {
        /** @var array<string, mixed> $config */
        $config = require __DIR__.'/../../../config/terraria.php';

        $config['allowed_world_keys'] = [self::WORLD_KEY];
        $config['supported_runtime'] = '4.3.13:1.3.0.8';
        $config['collection_chest_name'] = 'BACKLOG_COLLECTION';

        return new SnapshotRequestParser(SnapshotValidationSettings::fromConfig($config));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function body(array $overrides = []): string
    {
        return (string) json_encode(array_replace([
            'schemaVersion' => 1,
            'requestId' => '0199f136-9e36-7f41-b148-e5b4f384a321',
            'reason' => 'periodic',
            'observedAt' => '2026-09-16T10:00:00+09:00',
            'runtime' => [
                'adapterVersion' => '0.1.0',
                'tshockVersion' => '4.3.13',
                'terrariaVersion' => '1.3.0.8',
            ],
            'world' => ['key' => self::WORLD_KEY, 'terrariaWorldId' => 123456789],
            'collectionChestName' => 'BACKLOG_COLLECTION',
            'flags' => ['downedBoss1' => true],
            'collectionChests' => [],
        ], $overrides), JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function it_builds_a_validated_dto_from_the_contract_example(): void
    {
        $raw = file_get_contents(__DIR__.'/../../../../contracts/examples/snapshot-v1.json');
        $this->assertIsString($raw);

        $snapshot = $this->parser()->parse(self::WORLD_KEY, $raw);

        $this->assertSame(1, $snapshot->schemaVersion);
        $this->assertSame('0199f136-9e36-7f41-b148-e5b4f384a321', $snapshot->requestId);
        $this->assertSame(SnapshotReason::CollectionChange, $snapshot->reason);
        $this->assertSame('2026-09-16T10:00:00+09:00', $snapshot->observedAt->format('Y-m-d\TH:i:sP'));
        $this->assertFalse($snapshot->recoveryPending);
        $this->assertSame('4.3.13:1.3.0.8', $snapshot->runtime->pair());
        $this->assertSame(self::WORLD_KEY, $snapshot->worldKey()->value);
        $this->assertSame(123456789, $snapshot->world->terrariaWorldId);
        $this->assertSame('Fusic World', $snapshot->world->name);
        $this->assertSame('BACKLOG_COLLECTION', $snapshot->collectionChestName);
        $this->assertTrue($snapshot->flags->isSet('downedBoss1'));
        $this->assertTrue($snapshot->flags->isSet('downedBoss3'));
        $this->assertFalse($snapshot->flags->isSet('downedMoonlord'));
        $this->assertCount(1, $snapshot->collectionChests);
        $this->assertSame(['player1'], $snapshot->triggerPlayerNames);

        $item = $snapshot->collectionChests[0]->items[0];
        $this->assertTrue($item->isAccepted());
        $this->assertSame(1326, $item->type());
        $this->assertSame(1, $item->stack());
    }

    /**
     * @return array<string, array{0: string, 1: SnapshotRejectionCode}>
     */
    public static function jsonNumberEdgeCases(): array
    {
        return [
            'integer-like string world id' => ['"terrariaWorldId": "123456789"', SnapshotRejectionCode::InvalidTerrariaWorldId],
            'float world id' => ['"terrariaWorldId": 123456789.0', SnapshotRejectionCode::InvalidTerrariaWorldId],
            'boolean world id' => ['"terrariaWorldId": true', SnapshotRejectionCode::InvalidTerrariaWorldId],
        ];
    }

    #[Test]
    #[DataProvider('jsonNumberEdgeCases')]
    public function it_does_not_coerce_json_types(string $worldIdFragment, SnapshotRejectionCode $expected): void
    {
        $json = str_replace('"terrariaWorldId": 123456789', $worldIdFragment, <<<'JSON'
        {
          "schemaVersion": 1,
          "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
          "reason": "periodic",
          "observedAt": "2026-09-16T10:00:00+09:00",
          "runtime": {"adapterVersion": "0.1.0", "tshockVersion": "4.3.13", "terrariaVersion": "1.3.0.8"},
          "world": {"key": "terraria:123456789", "terrariaWorldId": 123456789},
          "collectionChestName": "BACKLOG_COLLECTION",
          "flags": {},
          "collectionChests": []
        }
        JSON);

        try {
            $this->parser()->parse(self::WORLD_KEY, $json);
            $this->fail('expected the snapshot to be rejected');
        } catch (SnapshotRejectedException $rejection) {
            $this->assertSame($expected, $rejection->rejectionCode);
        }
    }

    #[Test]
    public function a_float_stack_is_an_item_level_problem_not_a_request_level_one(): void
    {
        $json = <<<'JSON'
        {
          "schemaVersion": 1,
          "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
          "reason": "collection_change",
          "observedAt": "2026-09-16T10:00:00+09:00",
          "runtime": {"adapterVersion": "0.1.0", "tshockVersion": "4.3.13", "terrariaVersion": "1.3.0.8"},
          "world": {"key": "terraria:123456789", "terrariaWorldId": 123456789},
          "collectionChestName": "BACKLOG_COLLECTION",
          "flags": {},
          "collectionChests": [
            {"x": 1, "y": 2, "name": "BACKLOG_COLLECTION", "items": [{"type": 1326, "stack": 1.5}]}
          ]
        }
        JSON;

        $snapshot = $this->parser()->parse(self::WORLD_KEY, $json);

        $item = $snapshot->collectionChests[0]->items[0];
        $this->assertFalse($item->isAccepted());
        $this->assertSame([ItemRejectionReason::StackNotInteger], $item->rejections);
    }

    #[Test]
    public function rejection_codes_map_to_the_http_status_codes_the_design_requires(): void
    {
        $this->assertSame(409, SnapshotRejectionCode::UnsupportedRuntime->httpStatus());
        $this->assertSame(403, SnapshotRejectionCode::WorldKeyMismatch->httpStatus());
        $this->assertSame(403, SnapshotRejectionCode::WorldNotAllowed->httpStatus());
        $this->assertSame(413, SnapshotRejectionCode::BodyTooLarge->httpStatus());
        $this->assertSame(400, SnapshotRejectionCode::MalformedJson->httpStatus());
        $this->assertSame(503, SnapshotRejectionCode::BridgeMisconfigured->httpStatus());
        $this->assertSame(422, SnapshotRejectionCode::ForbiddenField->httpStatus());
        $this->assertSame(422, SnapshotRejectionCode::LimitExceeded->httpStatus());
    }

    #[Test]
    public function a_task04_catalog_rejection_can_be_appended_without_rebuilding_the_snapshot(): void
    {
        $snapshot = $this->parser()->parse(self::WORLD_KEY, $this->body());
        $this->assertSame([], $snapshot->collectionChests);

        $json = $this->body([
            'collectionChests' => [
                ['x' => 1, 'y' => 2, 'name' => 'BACKLOG_COLLECTION', 'items' => [['type' => 1326, 'stack' => 1]]],
            ],
        ]);

        $item = $this->parser()->parse(self::WORLD_KEY, $json)->collectionChests[0]->items[0];
        $this->assertTrue($item->isAccepted());

        // Task 04 (Item catalog) がこの API で判定結果を足す。
        $rejected = $item->withRejection(ItemRejectionReason::TypeNotInCatalog);
        $this->assertFalse($rejected->isAccepted());
        $this->assertSame([ItemRejectionReason::TypeNotInCatalog], $rejected->rejections);
        $this->assertTrue($item->isAccepted(), 'the original item stays immutable');
    }

    #[Test]
    public function normalizing_a_key_ignores_case_and_separators(): void
    {
        $this->assertSame('projectkey', SnapshotValidationSettings::normalizeKey('project_key'));
        $this->assertSame('projectkey', SnapshotValidationSettings::normalizeKey('Project-Key'));
        $this->assertSame('projectkey', SnapshotValidationSettings::normalizeKey('projectKey'));
        $this->assertSame('issuekey', SnapshotValidationSettings::normalizeKey('ISSUE KEY'));
    }
}
