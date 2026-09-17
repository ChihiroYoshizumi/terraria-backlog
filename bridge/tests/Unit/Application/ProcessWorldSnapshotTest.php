<?php

declare(strict_types=1);

namespace Tests\Unit\Application;

use App\Application\BuildNotifications;
use App\Application\EvaluateAchievements;
use App\Application\ProcessWorldSnapshot;
use App\Application\SynchronizeMappedIssues;
use App\Domain\Achievement\WorldFlagEvaluator;
use App\Domain\ItemCatalog\ItemEntryValidator;
use App\Domain\Mapping\CompletionFailureReason;
use App\Domain\Registry\RegistryFailureReason;
use App\Domain\Snapshot\NotificationAudience;
use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotOutcome;
use App\Domain\Snapshot\SnapshotReason;
use App\Domain\Snapshot\SnapshotResult;
use App\Domain\Snapshot\WorldSnapshot;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Support\FakeMappingRepository;
use Tests\Unit\Support\FakeRegistryRepository;
use Tests\Unit\Support\InMemoryItemCatalogRepository;
use Tests\Unit\Support\RecordingLogger;
use Tests\Unit\Support\SnapshotFactory;

/**
 * Task 07: Reconciliation / 通知 / 障害復旧
 * (docs/design.md §12, §14, §15, §17, §18 / AC-05, AC-06, AC-08, AC-09,
 * AC-10, AC-11, AC-17, AC-18)。
 *
 * 実 Backlog へは接続しない。Registry / Mapping は fake repository を使い、
 * 「保存確認できたか」「PATCH したか」だけを観測する。
 *
 * このテストが守っているのは主に **immediate player ACK と recovery server
 * notification の分離** である。両者は条件・宛先・文言がすべて異なるため、
 * それぞれ「1 件も出ないこと」「1 件だけ出ること」を厳密に assert する。
 */
final class ProcessWorldSnapshotTest extends TestCase
{
    private const string ROD_OF_DISCORD = 'item:1326';

    private const string LIFE_CRYSTAL = 'item:29';

    private const string EYE_OF_CTHULHU = 'boss:eye_of_cthulhu';

    private FakeRegistryRepository $registries;

    private FakeMappingRepository $mappings;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registries = new FakeRegistryRepository;
        $this->mappings = new FakeMappingRepository;
        $this->logger = new RecordingLogger;
    }

    // -----------------------------------------------------------------
    // immediate player ACK (collection_change)
    // -----------------------------------------------------------------

    #[Test]
    public function collection_change_acknowledges_the_triggering_players_for_each_persisted_item(): void
    {
        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
            playerNames: ['player1', 'player2', 'player1'],
        ));

        $this->assertSame(SnapshotOutcome::Completed, $result->outcome);
        $this->assertCount(1, $result->notifications);

        $notification = $result->notifications[0];
        $this->assertSame(NotificationAudience::Players, $notification->audience);
        // 同一 debounce window の重複 Player は排除する (docs/design.md §15.2)。
        $this->assertSame(['player1', 'player2'], $notification->playerNames);
        $this->assertSame('[Backlog] Rod of Discord を登録しました。取り出してOKです。', $notification->message);
    }

    #[Test]
    public function only_collection_change_produces_item_specific_player_acks(): void
    {
        foreach ([SnapshotReason::Startup, SnapshotReason::Periodic, SnapshotReason::Manual, SnapshotReason::WorldChange] as $reason) {
            $this->setUp();

            $result = $this->process(SnapshotFactory::make(
                reason: $reason,
                items: [['type' => 1326, 'stack' => 1]],
                playerNames: ['player1'],
            ));

            // Registry は保存されるが、Item 単位の ACK は collection_change のみ。
            $this->assertSame([self::ROD_OF_DISCORD], $this->registries->writes);
            $this->assertSame([], $result->notifications, $reason->value.' must not produce item ACKs');
        }
    }

    #[Test]
    public function an_unconfirmed_registry_save_produces_no_player_ack_at_all(): void
    {
        $this->registries->failWith(self::ROD_OF_DISCORD, RegistryFailureReason::WriteResultUnknown);

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
        ));

        // 「成功 ACK を返さない」= 通知が 1 件も無い (docs/spec.md AC-09)。
        $this->assertSame([], $result->notifications);
        $this->assertSame(SnapshotOutcome::RetriableFailure, $result->outcome);
        $this->assertSame(503, $result->httpStatus());
    }

    #[Test]
    public function a_fail_closed_duplicate_registry_produces_no_player_ack(): void
    {
        $this->registries->failClosedOnDuplicates(self::ROD_OF_DISCORD);

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
        ));

        $this->assertSame([], $result->notifications);
        $this->assertSame([], $this->registries->writes, 'fail closed では書き込まない');
    }

    #[Test]
    public function a_partial_failure_only_notifies_the_items_that_were_actually_persisted(): void
    {
        $this->registries->failWith(self::LIFE_CRYSTAL, RegistryFailureReason::WriteFailed);

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [
                ['type' => 1326, 'stack' => 1],
                ['type' => 29, 'stack' => 1],
            ],
        ));

        $this->assertCount(1, $result->notifications);
        $this->assertStringContainsString('Rod of Discord', $result->notifications[0]->message);

        // 失敗した Item を通知へ混ぜない (docs/design.md §15.3)。
        $this->assertStringNotContainsString('Life Crystal', $this->messages($result));

        // 成功分の ACK は 200 で届ける。成功 ACK を捏造しているわけではない。
        $this->assertSame(SnapshotOutcome::Completed, $result->outcome);
    }

    #[Test]
    public function a_mapping_update_failure_does_not_block_the_item_ack(): void
    {
        // ACK 条件は Registry 保存確認だけ。Mapping 完了の成否は含めない (§15)。
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-70');
        $this->mappings->failWith('TRAINING_YOSHIZUMI-70', CompletionFailureReason::WriteFailed);

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
        ));

        $this->assertCount(1, $result->notifications);
        $this->assertSame(NotificationAudience::Players, $result->notifications[0]->audience);
    }

    #[Test]
    public function a_collection_change_without_trigger_players_produces_no_notification(): void
    {
        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
            playerNames: [],
        ));

        // 宛先が無い ACK を全体チャットへ落とさない。
        $this->assertSame([], $result->notifications);
    }

    // -----------------------------------------------------------------
    // recovery notification (periodic / manual)
    // -----------------------------------------------------------------

    #[Test]
    public function a_successful_reconciliation_after_a_failure_emits_only_the_server_recovery_notice(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-71');

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [['type' => 1326, 'stack' => 1]],
            // 障害前に操作していた Player が Snapshot に載っていても復元しない。
            playerNames: ['player1'],
            recoveryPending: true,
        ));

        $this->assertCount(1, $result->notifications);

        $notification = $result->notifications[0];
        $this->assertSame(NotificationAudience::Server, $notification->audience);
        $this->assertSame([], $notification->playerNames);
        $this->assertSame(BuildNotifications::RECOVERY_MESSAGE, $notification->message);
    }

    #[Test]
    public function a_recovery_notification_never_restores_item_or_player_specific_acks(): void
    {
        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Manual,
            items: [['type' => 1326, 'stack' => 1]],
            playerNames: ['player1', 'player2'],
            recoveryPending: true,
        ));

        // server 向け 1 件「だけ」。過去 Player への item-specific ACK は作らない。
        $this->assertCount(1, $result->notifications);
        $this->assertSame(NotificationAudience::Server, $result->notifications[0]->audience);

        $rendered = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertStringNotContainsString('player1', $rendered);
        $this->assertStringNotContainsString('player2', $rendered);
        $this->assertStringNotContainsString('Rod of Discord', $rendered);
        $this->assertStringNotContainsString('取り出して', $rendered);
    }

    #[Test]
    public function a_partially_failed_recovery_sweep_emits_no_recovery_notification(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-72');
        $this->mappings->failWith('TRAINING_YOSHIZUMI-72', CompletionFailureReason::WriteResultUnknown);

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [['type' => 1326, 'stack' => 1]],
            recoveryPending: true,
        ));

        $this->assertSame([], $result->notifications);
        // 再試行可能な非成功応答 (docs/design.md §15.3)。
        $this->assertSame(SnapshotOutcome::RetriableFailure, $result->outcome);
    }

    #[Test]
    public function a_healthy_periodic_sweep_without_a_pending_recovery_emits_nothing(): void
    {
        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [['type' => 1326, 'stack' => 1]],
            recoveryPending: false,
        ));

        $this->assertSame([], $result->notifications);
        $this->assertSame(SnapshotOutcome::Completed, $result->outcome);
    }

    #[Test]
    public function a_collection_change_never_emits_the_recovery_notification(): void
    {
        // recoveryPending は periodic / manual にしか載らない契約だが、
        // 万一載っていても Item ACK 経路が復旧通知へ化けないことを固定する。
        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
            recoveryPending: true,
        ));

        $this->assertCount(1, $result->notifications);
        $this->assertSame(NotificationAudience::Players, $result->notifications[0]->audience);
    }

    // -----------------------------------------------------------------
    // reconciliation (post-hoc mapping / 再試行 / 冪等性)
    // -----------------------------------------------------------------

    #[Test]
    public function a_periodic_sweep_completes_a_mapping_added_after_the_registry_was_stored(): void
    {
        // Registry は既に保存済み。Item は既にチェストから取り出されている。
        $this->registries->seedCompleted(self::ROD_OF_DISCORD);
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-73');

        $this->process(SnapshotFactory::make(reason: SnapshotReason::Periodic, items: []));

        // AC-06: 後付け Mapping を現在の Registry だけで完了できる。
        $this->assertSame(['TRAINING_YOSHIZUMI-73'], $this->mappings->patches);
        $this->assertSame([], $this->registries->writes, 'Registry を作り直さない');
    }

    #[Test]
    public function a_manual_sync_reflects_a_post_hoc_mapping_immediately(): void
    {
        $this->registries->seedCompleted(self::EYE_OF_CTHULHU);
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::EYE_OF_CTHULHU, 'TRAINING_YOSHIZUMI-74');

        $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Manual,
            items: [],
            flags: ['downedBoss1' => true],
        ));

        // `/backlog sync` 相当でも periodic と同じ再照合を最後まで行う (§14)。
        $this->assertSame(['TRAINING_YOSHIZUMI-74'], $this->mappings->patches);
    }

    #[Test]
    public function a_failed_mapping_update_is_retried_and_completed_by_the_next_sync(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-75');
        $this->mappings->failWith('TRAINING_YOSHIZUMI-75', CompletionFailureReason::WriteResultUnknown);

        $first = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
        ));

        $this->assertSame([], $this->mappings->patches);
        // Registry は残る。巻き戻さない (AC-10)。
        $this->assertSame([self::ROD_OF_DISCORD], $this->registries->writes);
        $this->assertCount(1, $first->notifications, 'Registry 保存済みなので ACK は出る');

        // Backlog が復旧し、次回 periodic で再試行する。
        $this->mappings = new FakeMappingRepository;
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-75');

        $this->process(SnapshotFactory::make(reason: SnapshotReason::Periodic, items: []));

        $this->assertSame(['TRAINING_YOSHIZUMI-75'], $this->mappings->patches);
        $this->assertSame([self::ROD_OF_DISCORD], $this->registries->writes, 'Registry を再作成しない');
    }

    #[Test]
    public function repeated_reconciliation_issues_no_duplicate_create_or_patch(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-76');
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::EYE_OF_CTHULHU, 'TRAINING_YOSHIZUMI-77');

        $snapshot = SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [['type' => 1326, 'stack' => 1]],
            flags: ['downedBoss1' => true],
        );

        $this->process($snapshot);

        $writesAfterFirst = $this->registries->writes;
        $patchesAfterFirst = $this->mappings->patches;

        $this->assertSame([self::EYE_OF_CTHULHU, self::ROD_OF_DISCORD], $writesAfterFirst);
        // join は完了済み Registry Key 順 (boss: -> item:)。
        $this->assertSame(['TRAINING_YOSHIZUMI-77', 'TRAINING_YOSHIZUMI-76'], $patchesAfterFirst);

        // 2 回目・3 回目は同じ観測でも一切書き込まない (AC-11)。
        $this->process($snapshot);
        $this->process($snapshot);

        $this->assertSame($writesAfterFirst, $this->registries->writes, 'Registry を重複作成した');
        $this->assertSame($patchesAfterFirst, $this->mappings->patches, '完了済み課題へ再 PATCH した');
    }

    #[Test]
    public function registry_and_mapping_are_scanned_once_per_snapshot(): void
    {
        $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [
                ['type' => 1326, 'stack' => 1],
                ['type' => 29, 'stack' => 1],
                ['type' => 1291, 'stack' => 1],
            ],
            flags: ['downedBoss1' => true, 'hardMode' => true],
        ));

        // Achievement は 5 件あるが full scan は各 1 回だけ (docs/design.md §12, §20)。
        $this->assertCount(5, $this->registries->ensureCalls);
        $this->assertCount(1, $this->registries->indexLoads);
        $this->assertCount(1, $this->mappings->indexLoads);
    }

    #[Test]
    public function an_invalid_item_does_not_stop_the_boss_world_registry_or_the_mapping_sync(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::EYE_OF_CTHULHU, 'TRAINING_YOSHIZUMI-78');

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [
                ['type' => '1326', 'stack' => 1],   // 型 coercion しない -> 構造 reject
                ['type' => 999999, 'stack' => 1],   // catalog に存在しない
                ['type' => 1326, 'stack' => 0],     // stack が正でない
                ['type' => 29, 'stack' => 1],       // 有効
            ],
            flags: ['downedBoss1' => true, 'hardMode' => true],
        ));

        // AC-17: 不正 Item から Registry を作らない。有効な分だけ進む。
        $this->assertSame(
            [self::EYE_OF_CTHULHU, 'world:hardmode', self::LIFE_CRYSTAL],
            $this->registries->writes,
        );
        $this->assertSame(['TRAINING_YOSHIZUMI-78'], $this->mappings->patches);

        // 不正 Item は処理失敗に含めない。成功として扱える (§15.2)。
        $this->assertSame(SnapshotOutcome::Completed, $result->outcome);

        // 通知は有効 Item のぶんだけ。Rod of Discord は ACK しない。
        $this->assertCount(1, $result->notifications);
        $this->assertStringContainsString('Life Crystal', $result->notifications[0]->message);
    }

    #[Test]
    public function a_registry_scan_failure_is_never_read_as_nothing_registered(): void
    {
        $this->registries->breakIndexLoad();

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
        ));

        $this->assertSame([], $result->notifications);
        $this->assertSame(SnapshotOutcome::RetriableFailure, $result->outcome);
        $this->assertSame([], $this->registries->writes);
        $this->assertSame([], $this->mappings->indexLoads, 'scan が落ちたら以降へ進まない');
    }

    #[Test]
    public function a_mapping_scan_failure_does_not_roll_back_the_registry_and_stays_retriable(): void
    {
        $this->registries->seedCompleted(self::ROD_OF_DISCORD);
        $this->mappings->breakIndexLoad();

        $result = $this->process(SnapshotFactory::make(
            reason: SnapshotReason::Periodic,
            items: [['type' => 1326, 'stack' => 1]],
            recoveryPending: true,
        ));

        $this->assertSame([], $result->notifications);
        $this->assertSame(SnapshotOutcome::RetriableFailure, $result->outcome);
        $this->assertTrue($this->registries->stored[self::ROD_OF_DISCORD]);
    }

    // -----------------------------------------------------------------
    // 構造化ログ
    // -----------------------------------------------------------------

    #[Test]
    public function the_pipeline_emits_the_documented_structured_log_operations(): void
    {
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::ROD_OF_DISCORD, 'TRAINING_YOSHIZUMI-79');
        $this->mappings->addMapping(SnapshotFactory::WORLD_KEY, self::EYE_OF_CTHULHU, 'TRAINING_YOSHIZUMI-80');
        $this->mappings->failWith('TRAINING_YOSHIZUMI-80', CompletionFailureReason::WriteFailed);
        $this->registries->seedCompleted(self::EYE_OF_CTHULHU);
        $this->registries->failWith(self::LIFE_CRYSTAL, RegistryFailureReason::LookupFailed);

        $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1], ['type' => 29, 'stack' => 1]],
            flags: ['downedBoss1' => true],
        ));

        foreach ([
            'snapshot.received',
            'registry.created',
            'registry.exists',
            'registry.failed',
            'mapping.index_loaded',
            'issue.completed',
            'issue.completion_failed',
            'notification.generated',
            'reconciliation.completed',
        ] as $operation) {
            $this->assertNotEmpty(
                $this->logger->withMessage($operation),
                sprintf('%s が出力されていない (docs/design.md §17)', $operation),
            );
        }
    }

    #[Test]
    public function structured_logs_never_carry_player_names(): void
    {
        $this->process(SnapshotFactory::make(
            reason: SnapshotReason::CollectionChange,
            items: [['type' => 1326, 'stack' => 1]],
            playerNames: ['SecretPlayerName'],
        ));

        $rendered = json_encode($this->logger->records, JSON_UNESCAPED_UNICODE) ?: '';

        // docs/spec.md §10 / docs/design.md §17: 不要な Player 情報を残さない。
        $this->assertStringNotContainsString('SecretPlayerName', $rendered);
    }

    // -----------------------------------------------------------------

    private function process(WorldSnapshot $snapshot): SnapshotResult
    {
        $processor = new ProcessWorldSnapshot(
            achievements: new EvaluateAchievements(
                new InMemoryItemCatalogRepository(SnapshotFactory::TERRARIA_VERSION, [
                    29 => ['Life Crystal', 99],
                    1291 => ['Life Fruit', 99],
                    1326 => ['Rod of Discord', 1],
                ]),
                new WorldFlagEvaluator,
                new ItemEntryValidator,
                $this->logger,
            ),
            registries: $this->registries,
            mappings: $this->mappings,
            synchronize: new SynchronizeMappedIssues($this->mappings, $this->logger),
            notifications: new BuildNotifications,
            logger: $this->logger,
        );

        return $processor->process($snapshot);
    }

    private function messages(SnapshotResult $result): string
    {
        return implode("\n", array_map(
            static fn (SnapshotNotification $notification): string => $notification->message,
            $result->notifications,
        ));
    }
}
