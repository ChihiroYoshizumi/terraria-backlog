<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Achievement\Achievement;
use App\Domain\Mapping\CompletionResult;
use App\Domain\Mapping\MappingIndex;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Registry\RegistryRepository;
use App\Domain\Registry\RegistryResult;
use App\Domain\Snapshot\CollectionChest;
use App\Domain\Snapshot\ObservedItem;
use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotProcessor;
use App\Domain\Snapshot\SnapshotResult;
use App\Domain\Snapshot\WorldKey;
use App\Domain\Snapshot\WorldSnapshot;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * validated Snapshot 1 件を最後まで処理する唯一の Application service
 * (docs/design.md §12, §14, §15)。
 *
 * ```text
 * 1. Item 単位 validation / Achievement 評価   (Task 04)
 * 2. Registry scan -> registryIndex            (Task 05, Snapshot ごとに1回)
 * 3. Mapping scan  -> mappingIndex             (Task 06, Snapshot ごとに1回)
 * 4. Achievement ごとに Registry ensure
 * 5. 保存確認済み Registry set を確定
 * 6. Registry set と mappingIndex を join
 * 7. 対応 Mapping Issue を完了
 * 8. notification を生成
 * 9. notification-only response を返す
 * ```
 *
 * `startup` / `periodic` / `manual` / `collection_change` / `world_change` は
 * **すべてこの 1 本を通る**。reason ごとに Achievement 判定や Registry / Mapping
 * 同期を分岐させない (docs/design.md §14)。reason が効くのは手順 8 の通知生成だけで、
 * その分岐は {@see BuildNotifications} に閉じている。
 *
 * request-level validation は Task 02 (`SnapshotRequestParser`) の責務であり、
 * ここでは validated DTO を前提にする。
 *
 * ## 障害時の原則
 *
 * - Registry 保存を確認できない Achievement を成功扱いしない。
 * - Mapping 更新だけが失敗しても Registry はそのまま残し、次回 reconciliation で
 *   再試行できる状態にする (AC-10)。永続 retry queue は持たない。
 * - 不正 Item は Achievement 候補に入らないだけで、同 Snapshot の Boss / World
 *   Registry と Registry→Mapping 同期は継続する (AC-17, docs/design.md §18.3)。
 * - Backlog scan / 評価そのものが落ちた場合は成功 ACK を返さず、
 *   再試行可能な非成功応答へ倒す (docs/design.md §18.2)。
 *
 * ## ログ
 *
 * docs/design.md §17 の代表 operation を **request 相関付き** で出す。
 * Infrastructure 層 (`BacklogRegistryRepository` / `BacklogMappingRepository`) も
 * 同名の低レベルログを出すが、そちらには `request_id` / `reason` が無い。
 * 秘密情報と Player Name はログへ出さない (docs/spec.md §10, docs/design.md §17)。
 */
final readonly class ProcessWorldSnapshot implements SnapshotProcessor
{
    public function __construct(
        private EvaluateAchievements $achievements,
        private RegistryRepository $registries,
        private MappingRepository $mappings,
        private SynchronizeMappedIssues $synchronize,
        private BuildNotifications $notifications,
        private LoggerInterface $logger = new NullLogger,
    ) {}

    public function process(WorldSnapshot $snapshot): SnapshotResult
    {
        $world = $snapshot->worldKey();
        $context = $snapshot->logContext();

        $this->logger->info('snapshot.received', $context + [
            'operation' => 'snapshot.received',
            // ワールド単位の復旧待ちフラグ。Player 情報は含まない (§15.2)。
            'recovery_pending' => $snapshot->recoveryPending,
            'chests' => count($snapshot->collectionChests),
            'accepted_items' => $this->countItems($snapshot, accepted: true),
            'rejected_items' => count($snapshot->rejectedItems()),
            // Player Name はログに残さない。宛先の有無だけを件数で記録する。
            'trigger_players' => count($snapshot->triggerPlayerNames),
        ]);

        // --- 1. Achievement 評価 ------------------------------------------
        // 不正 Item はここで候補から外れるだけで、Boss / World の評価は続く。
        try {
            $achievements = $this->achievements->evaluate($this->toEvaluationInput($snapshot));
        } catch (Throwable $exception) {
            // catalog 不在などは設定不備として fail closed (docs/design.md §18.4)。
            return $this->abort($snapshot, 'achievement.evaluation_failed', $exception);
        }

        // --- 2. Registry scan (Snapshot ごとに1回) --------------------------
        try {
            $registryIndex = $this->registries->loadIndex($world);
        } catch (Throwable $exception) {
            // 検索障害を「未登録」と読み替えない (docs/design.md §13.4, §20)。
            return $this->abort($snapshot, 'registry.failed', $exception);
        }

        // --- 3. Mapping scan (Snapshot ごとに1回) ---------------------------
        // periodic / manual でも毎回読み直すため、後付け Mapping をここで拾える
        // (AC-06, docs/design.md §14)。
        try {
            $mappingIndex = $this->mappings->loadIncompleteIndex($world);
        } catch (Throwable $exception) {
            return $this->abort($snapshot, 'mapping.index_load_failed', $exception);
        }

        $this->logger->info('mapping.index_loaded', $context + [
            'operation' => 'mapping.index_loaded',
            'mappings' => $mappingIndex->count(),
            'achievement_keys' => count($mappingIndex->achievementKeys()),
        ]);

        // --- 4, 5. Registry ensure と保存確認済み set の確定 ----------------
        $registry = $this->ensureRegistered($snapshot, $world, $achievements, $registryIndex);

        // --- 6, 7. Registry set と mappingIndex の join -> 課題完了 ----------
        $sync = $this->completeMappedIssues($snapshot, $world, $registryIndex, $mappingIndex);

        if ($sync !== null) {
            $this->logCompletions($context, $sync);
        }

        // 「復旧成功」= 有効な達成候補の Registry 保存確認と、Mapping 再照合が
        // どちらもエラーなく終わったこと (docs/design.md §15.2)。
        // skip した不正 Item は失敗に含めない。
        $reconciled = $registry['failed'] === 0 && $sync !== null && ! $sync->hasFailures();

        // --- 8. 通知生成 ---------------------------------------------------
        // 2 経路は互いに独立。immediate ACK は Mapping の成否を見ず、
        // recovery notification は Item / Player を一切参照しない。
        $notifications = [
            ...$this->notifications->immediatePlayerAcks($snapshot, $registry['confirmedItems']),
            ...$this->notifications->recoveryNotifications($snapshot, $reconciled),
        ];

        // --- 9. notification-only response ---------------------------------
        return $this->result($snapshot, $notifications, $reconciled, $registry, $sync);
    }

    /**
     * 手順 4, 5。Achievement ごとに Registry の存在を保証し、保存確認できたものだけを
     * ACK 候補に残す。
     *
     * @param  list<Achievement>  $achievements
     * @return array{created: int, exists: int, failed: int, confirmedItems: list<ConfirmedItemRegistration>}
     */
    private function ensureRegistered(
        WorldSnapshot $snapshot,
        WorldKey $world,
        array $achievements,
        RegistryIndex $index,
    ): array {
        $context = $snapshot->logContext();
        $summary = ['created' => 0, 'exists' => 0, 'failed' => 0, 'confirmedItems' => []];

        foreach ($achievements as $achievement) {
            try {
                $result = $this->registries->ensureRegistered($world, $achievement, $index);
            } catch (Throwable $exception) {
                // ensureRegistered は RegistryResult を返す契約だが、1 Achievement の
                // 予期しない失敗で Snapshot 全体を落とさない。
                $summary['failed']++;

                $this->logger->error('registry.failed', $context + [
                    'operation' => 'registry.ensure',
                    'achievement_key' => $achievement->keyString(),
                    'result' => 'failed',
                    'error_type' => $exception::class,
                ]);

                continue;
            }

            $this->logRegistryResult($context, $achievement, $result);

            if (! $result->isPersisted()) {
                $summary['failed']++;

                continue;
            }

            $result->wasWritten() ? $summary['created']++ : $summary['exists']++;

            // Item 以外 / 保存未確認はここで null になり ACK 経路へ入らない。
            $confirmed = ConfirmedItemRegistration::fromRegistryResult($achievement, $result);

            if ($confirmed !== null) {
                $summary['confirmedItems'][] = $confirmed;
            }
        }

        return $summary;
    }

    /**
     * 手順 6, 7。完了済み Registry set と未完了 Mapping を join して課題を完了する。
     *
     * scan 済みの index を渡すため、ここで full scan は増えない。
     */
    private function completeMappedIssues(
        WorldSnapshot $snapshot,
        WorldKey $world,
        RegistryIndex $registryIndex,
        MappingIndex $mappingIndex,
    ): ?SynchronizeMappedIssuesResult {
        try {
            return $this->synchronize->synchronizeWithRegistry($world, $registryIndex, $mappingIndex);
        } catch (Throwable $exception) {
            // Registry は既に保存済みであり、次回 reconciliation で再試行できる
            // (AC-10)。ここで Registry を巻き戻さない。
            $this->logger->error('issue.completion_failed', $snapshot->logContext() + [
                'operation' => 'mapping.sync',
                'result' => 'failed',
                'error_type' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * 手順 9。notification-only の結果と、HTTP status 用の outcome を確定する。
     *
     * 再照合を完了できなかった場合でも、返せる通知 (= 保存確認済み Item の ACK) が
     * あるなら 200 で届ける。`collection_change` の partial failure で成功分の ACK を
     * 落とさないため (docs/design.md §15.3)。通知が無いなら再試行可能な非成功応答に
     * 倒し、Adapter に復旧待ちフラグを立てさせる (§15.2, §18.2)。
     *
     * @param  list<SnapshotNotification>  $notifications
     * @param  array{created: int, exists: int, failed: int, confirmedItems: list<ConfirmedItemRegistration>}  $registry
     */
    private function result(
        WorldSnapshot $snapshot,
        array $notifications,
        bool $reconciled,
        array $registry,
        ?SynchronizeMappedIssuesResult $sync,
    ): SnapshotResult {
        $context = $snapshot->logContext();

        foreach ($notifications as $notification) {
            $this->logger->info('notification.generated', $context + [
                'operation' => 'notification.generated',
                'audience' => $notification->audience->value,
                // Player Name は残さない (docs/design.md §17)。
                'recipients' => count($notification->playerNames),
            ]);
        }

        $result = $reconciled || $notifications !== []
            ? new SnapshotResult($notifications)
            : SnapshotResult::retriableFailure();

        $this->logger->info('reconciliation.completed', $context + [
            'operation' => 'reconciliation.completed',
            'recovery_pending' => $snapshot->recoveryPending,
            'result' => $result->outcome->value,
            'reconciled' => $reconciled,
            'registry_created' => $registry['created'],
            'registry_exists' => $registry['exists'],
            'registry_failed' => $registry['failed'],
            'issues_completed' => $sync?->updatedCount() ?? 0,
            'issues_failed' => $sync === null ? null : count($sync->failures()),
            'notifications' => count($notifications),
        ]);

        return $result;
    }

    /**
     * 再照合を始められなかった / 続けられなかった場合 (docs/design.md §18.2)。
     */
    private function abort(WorldSnapshot $snapshot, string $operation, Throwable $exception): SnapshotResult
    {
        $this->logger->error($operation, $snapshot->logContext() + [
            'operation' => $operation,
            'result' => 'failed',
            'error_type' => $exception::class,
            // 例外 message は Backlog client 側で redact 済み。ここでは型だけ残す。
        ]);

        $this->logger->info('reconciliation.completed', $snapshot->logContext() + [
            'operation' => 'reconciliation.completed',
            'recovery_pending' => $snapshot->recoveryPending,
            'result' => 'retriable_failure',
            'reconciled' => false,
            'notifications' => 0,
        ]);

        return SnapshotResult::retriableFailure();
    }

    /**
     * @param  array{request_id: string, world_key: string, reason: string}  $context
     */
    private function logRegistryResult(array $context, Achievement $achievement, RegistryResult $result): void
    {
        $payload = $context + [
            'operation' => 'registry.ensure',
            'achievement_key' => $achievement->keyString(),
            'registry_issue_key' => $result->issue?->issueKey,
            'result' => $result->status->value,
        ];

        if (! $result->isPersisted()) {
            $this->logger->warning('registry.failed', $payload + ['error_type' => $result->reason?->value]);

            return;
        }

        if ($result->wasWritten()) {
            $this->logger->info('registry.created', $payload);

            return;
        }

        $this->logger->info('registry.exists', $payload + [
            'duplicates' => $result->hasPhysicalDuplicates(),
        ]);
    }

    /**
     * @param  array{request_id: string, world_key: string, reason: string}  $context
     */
    private function logCompletions(array $context, SynchronizeMappedIssuesResult $sync): void
    {
        foreach ($sync->results as $completion) {
            if ($completion->wasUpdated()) {
                $this->logger->info('issue.completed', $context + $this->completionContext($completion));

                continue;
            }

            if ($completion->isFailure()) {
                $this->logger->warning('issue.completion_failed', $context + $this->completionContext($completion));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function completionContext(CompletionResult $completion): array
    {
        return [
            'operation' => 'mapping.complete',
            'achievement_key' => $completion->achievementKey,
            'mapping_issue_key' => $completion->issueKey,
            'result' => $completion->status->value,
            'error_type' => $completion->reason?->value,
        ];
    }

    /**
     * Snapshot DTO を評価器の入力へ落とす。
     *
     * catalog 照合は Task 04 の責務なので、構造 reject 済みの Item も
     * **受信したままの生の値** で渡し、評価器自身に判定させる
     * (docs/design.md §6.4 / tasks/04)。
     */
    private function toEvaluationInput(WorldSnapshot $snapshot): EvaluateAchievementsInput
    {
        $chests = [];

        foreach ($snapshot->collectionChests as $chest) {
            $chests[] = [
                'x' => $chest->x,
                'y' => $chest->y,
                'name' => $chest->name,
                'items' => array_map(
                    static fn (ObservedItem $item): array => ['type' => $item->rawType, 'stack' => $item->rawStack],
                    $chest->items,
                ),
            ];
        }

        return new EvaluateAchievementsInput(
            worldKey: $snapshot->worldKey()->value,
            terrariaVersion: $snapshot->runtime->terrariaVersion,
            flags: $snapshot->flags->all(),
            collectionChests: $chests,
            requestId: $snapshot->requestId,
        );
    }

    private function countItems(WorldSnapshot $snapshot, bool $accepted): int
    {
        $total = 0;

        foreach ($snapshot->collectionChests as $chest) {
            /** @var CollectionChest $chest */
            $total += count($accepted ? $chest->acceptedItems() : $chest->rejectedItems());
        }

        return $total;
    }
}
