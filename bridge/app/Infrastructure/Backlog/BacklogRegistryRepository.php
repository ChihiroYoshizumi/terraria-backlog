<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Domain\Achievement\Achievement;
use App\Domain\Registry\RegistryFailureReason;
use App\Domain\Registry\RegistryIndex;
use App\Domain\Registry\RegistryIssue;
use App\Domain\Registry\RegistryRecordType;
use App\Domain\Registry\RegistryRepository;
use App\Domain\Registry\RegistryResult;
use App\Domain\Registry\RegistrySubject;
use App\Domain\Snapshot\WorldKey;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Lock\CrossProcessLockFactory;
use App\Infrastructure\Lock\LockUnavailableException;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Backlog を Achievement Registry の永続ストレージとして使う実装
 * (docs/design.md §10, §20, §21 / AC-03, AC-05, AC-11, AC-12, AC-13)。
 *
 * 守るべき不変条件:
 *
 * - 保存確認が取れるまで success を返さない。
 * - 検索障害を「該当0件」と読み替えない。
 * - Backlog の検索文字列を exact lookup とみなさず、PHP 側で Project ID /
 *   Record Type / World Key / Terraria Key の完全一致を必ず確認する。
 * - 書き込みは `world_key + achievement_key` の cross-process file lock 内でのみ行う。
 * - exact Registry が複数 & 完了済み0件なら書き込まず fail closed。
 * - Project / Custom Field / Status ID は validated ProjectConfiguration からのみ取る。
 *   Adapter payload 由来の値は一切使わない (docs/design.md §16.3)。
 */
final class BacklogRegistryRepository implements RegistryRepository
{
    private ?RegistryIssueMapper $mapper = null;

    public function __construct(
        private readonly ProjectConfigurationRepository $projects,
        private readonly IssueListPaginator $paginator,
        private readonly BacklogClient $client,
        private readonly CrossProcessLockFactory $locks,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * 対象 Project / World の Registry を一度だけ全ページ取得して index 化する
     * (docs/design.md §10.3, §20)。
     *
     * Snapshot 単位で 1 回だけ呼ぶ。Achievement ごとに呼ぶと全件 scan を繰り返す。
     */
    public function loadIndex(WorldKey $world): RegistryIndex
    {
        $configuration = $this->projects->resolve();

        // Issue List query は対象 Project ID へ限定する。検索障害は例外として
        // 伝播させ、呼び出し側で「0件」と解釈させない (docs/design.md §20)。
        $issues = $this->paginator->fetchAll($configuration->projectId);

        $index = new RegistryIndex($world, $configuration->projectId);

        foreach ($this->matchRegistries($configuration, $issues, $world, null) as $registry) {
            $index->add($registry);
        }

        $this->logger->info('registry.index_loaded', [
            'operation' => 'registry.index_loaded',
            'worldKey' => $world->value,
            'projectId' => $configuration->projectId,
            'scannedIssues' => count($issues),
            'registryIssues' => $index->count(),
            'achievementKeys' => count($index->achievementKeys()),
        ]);

        return $index;
    }

    /**
     * docs/design.md §10.4 の分岐。
     *
     * ```text
     * exact Registry が0件   -> lock -> 最新確認 -> 作成 -> 完了更新 -> 保存確認
     * exact Registry が1件   -> 完了済み: already_registered
     *                        -> 未完了  : lock -> 最新確認 -> 完了更新 -> 保存確認
     * exact Registry が複数  -> 完了済み1件以上: 論理 already_registered (cleanup しない)
     *                        -> 完了済み0件   : fail closed (作成も自動完了もしない)
     * ```
     */
    public function ensureRegistered(
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult {
        $configuration = $this->projects->resolve();
        $achievementKey = $achievement->keyString();

        // index は「対象 World の、対象 Project の」Registry しか持たない前提で
        // 判定する。取り違えは World A の記録で World B を完了させる事故になる。
        if ($index->world->value !== $world->value || $index->projectId !== $configuration->projectId) {
            throw new InvalidArgumentException('registry index が対象 World / Project と一致しない.');
        }

        $matches = $index->forKey($achievementKey);

        // --- exact Registry が複数 -----------------------------------------
        // 書き込みを伴わないため lock は取らない。
        if (count($matches) > 1) {
            return $this->resolveDuplicates($world, $achievement, $matches);
        }

        // --- exact Registry が1件 / 完了済み -------------------------------
        // AC-11: 完了済み課題へ不要な更新をしない。API も呼ばない。
        if (count($matches) === 1 && $matches[0]->done) {
            return RegistryResult::alreadyRegistered($world, $achievement, $matches[0]);
        }

        // --- 書き込みが必要な分岐 (0件 / 1件未完了) ------------------------
        // docs/design.md §10.5: 「最新 exact Registry 確認 -> create/update ->
        // 保存確認」の全体を cross-process lock 内で行う。
        try {
            $lock = $this->locks->forAchievement($world->value, $achievementKey);
        } catch (LockUnavailableException $exception) {
            $this->logger->warning('registry.lock_unavailable', [
                'operation' => 'registry.ensure',
                'worldKey' => $world->value,
                'achievementKey' => $achievementKey,
                'error_type' => RegistryFailureReason::LockUnavailable->value,
                'detail' => $exception->getMessage(),
            ]);

            // lock を取れないまま書き込むと重複を防げない。書き込まず failed。
            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::LockUnavailable,
                'cross-process lock を取得できなかったため書き込みを行わなかった。',
            );
        }

        try {
            return $this->writeUnderLock($configuration, $world, $achievement, $index);
        } finally {
            $lock->release();
        }
    }

    /**
     * lock 内の処理 (docs/design.md §21 Registry 新規作成)。
     */
    private function writeUnderLock(
        ProjectConfiguration $configuration,
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult {
        $achievementKey = $achievement->keyString();

        // lock を取る前後で別 Worker が作成している可能性があるため、
        // lock 内で必ず最新 exact Registry を取り直す。
        try {
            $latest = $this->searchExact($configuration, $world, $achievementKey);
        } catch (BacklogApiException $exception) {
            // 検索障害を「該当0件」と扱わない。ここで作成すると重複を生む。
            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::LookupFailed,
                'lock 内の最新 Registry 確認に失敗した: '.$this->client->redact($exception->getMessage()),
            );
        }

        $index->replace($achievementKey, $latest);

        if (count($latest) > 1) {
            return $this->resolveDuplicates($world, $achievement, $latest);
        }

        if (count($latest) === 1) {
            $existing = $latest[0];

            if ($existing->done) {
                return RegistryResult::alreadyRegistered($world, $achievement, $existing);
            }

            // 未完了 Registry の修復。新規作成はしない。
            return $this->completeAndVerify($configuration, $world, $achievement, $index, $existing, true);
        }

        return $this->createAndVerify($configuration, $world, $achievement, $index);
    }

    /**
     * Registry Issue を作成し、完了状態にしてから保存確認する。
     */
    private function createAndVerify(
        ProjectConfiguration $configuration,
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult {
        try {
            $response = $this->client->post('/api/v2/issues', $this->createPayload($configuration, $world, $achievement));
        } catch (BacklogApiException $exception) {
            // timeout 等で作成有無が不明。即座に作り直さず再検索する。
            return $this->recoverFromUnknownWrite($configuration, $world, $achievement, $index, 'create', $exception);
        }

        $created = $this->mapper($configuration)->map($response->object());

        if ($created === null || ! $created->matches($configuration->projectId, $world->value, $achievement->keyString())) {
            // 201 は返ったが期待した Registry ではない。応答だけで成功と断定せず再検索する。
            return $this->recoverFromUnknownWrite(
                $configuration,
                $world,
                $achievement,
                $index,
                'create',
                new BacklogApiException('Registry 作成応答が期待した Registry と一致しない.'),
            );
        }

        return $this->completeAndVerify($configuration, $world, $achievement, $index, $created, true);
    }

    /**
     * Done Status へ更新し、GET による再取得で保存を確認する
     * (docs/design.md §10.4「再取得確認」, §21)。
     */
    private function completeAndVerify(
        ProjectConfiguration $configuration,
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
        RegistryIssue $target,
        bool $allowRecovery,
    ): RegistryResult {
        $achievementKey = $achievement->keyString();

        if (! $target->done) {
            try {
                // 状態 ID は validated config から。表示名や固定値を前提にしない。
                $this->client->patch(
                    '/api/v2/issues/'.rawurlencode($target->issueKey),
                    ['statusId' => $configuration->doneStatusId],
                );
            } catch (BacklogApiException $exception) {
                if ($allowRecovery) {
                    return $this->recoverFromUnknownWrite($configuration, $world, $achievement, $index, 'update', $exception);
                }

                return $this->failed(
                    $world,
                    $achievement,
                    RegistryFailureReason::WriteResultUnknown,
                    'Registry の完了更新結果を確認できなかった: '.$this->client->redact($exception->getMessage()),
                );
            }
        }

        // PATCH の応答だけを信用せず、GET で保存を確認する。
        try {
            $verified = $this->fetchExact($configuration, $world, $achievementKey, $target->issueKey);
        } catch (BacklogApiException $exception) {
            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::VerificationFailed,
                'Registry の保存確認 (GET) に失敗した: '.$this->client->redact($exception->getMessage()),
            );
        }

        if ($verified === null || ! $verified->done) {
            $this->logger->warning('registry.verification_failed', [
                'operation' => 'registry.ensure',
                'worldKey' => $world->value,
                'achievementKey' => $achievementKey,
                'issueKey' => $target->issueKey,
                'error_type' => RegistryFailureReason::VerificationFailed->value,
            ]);

            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::VerificationFailed,
                '再取得した Registry が完了状態であることを確認できなかった。',
            );
        }

        $index->replace($achievementKey, [$verified]);

        $this->logger->info('registry.registered', [
            'operation' => 'registry.ensure',
            'worldKey' => $world->value,
            'achievementKey' => $achievementKey,
            'issueKey' => $verified->issueKey,
            'result' => 'registered',
        ]);

        return RegistryResult::registered($world, $achievement, $verified);
    }

    /**
     * create / update の応答が不明になった場合の復旧 (docs/design.md §10.4, §13.4)。
     *
     * - 即座に作り直さない。
     * - 対象 World Key + Achievement Key を限定再検索する。
     * - 既存 Registry を確認できたらその状態から継続する。
     * - 確認できなければ success と断定しない。
     *
     * 限定再検索が「0件」を返した場合も、この pass では新規作成しない。
     * Backlog の Custom Field 文字列検索を exact lookup とみなさない方針
     * (docs/design.md §10.3) のもとでは 0件を「存在しない」の確証にできず、
     * ここで作成すると物理重複を生むため。次回 Snapshot の全件 scan
     * (`loadIndex`) が正本となり、そこで作成または修復される。
     */
    private function recoverFromUnknownWrite(
        ProjectConfiguration $configuration,
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
        string $operation,
        BacklogApiException $cause,
    ): RegistryResult {
        $achievementKey = $achievement->keyString();

        $this->logger->warning('registry.write_result_unknown', [
            'operation' => 'registry.'.$operation,
            'worldKey' => $world->value,
            'achievementKey' => $achievementKey,
            'error_type' => $cause->errorType(),
            'detail' => $this->client->redact($cause->getMessage()),
        ]);

        try {
            $latest = $this->searchExact($configuration, $world, $achievementKey);
        } catch (BacklogApiException $exception) {
            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::WriteResultUnknown,
                sprintf(
                    '%s の結果が不明で、再検索も失敗した: %s',
                    $operation,
                    $this->client->redact($exception->getMessage()),
                ),
            );
        }

        $index->replace($achievementKey, $latest);

        if (count($latest) > 1) {
            return $this->resolveDuplicates($world, $achievement, $latest);
        }

        if ($latest === []) {
            return $this->failed(
                $world,
                $achievement,
                RegistryFailureReason::WriteResultUnknown,
                sprintf('%s の結果が不明で、再検索でも Registry を確認できなかった。再作成はしない。', $operation),
            );
        }

        $existing = $latest[0];

        if ($existing->done) {
            // 保存は成功していた。二重作成せずその状態から成功として継続する。
            $this->logger->info('registry.recovered', [
                'operation' => 'registry.'.$operation,
                'worldKey' => $world->value,
                'achievementKey' => $achievementKey,
                'issueKey' => $existing->issueKey,
                'result' => 'registered',
            ]);

            return RegistryResult::registered($world, $achievement, $existing);
        }

        // 未完了で存在する = 作成までは通っていた。その状態から完了させる。
        // 再帰的な recovery は行わない (1 Snapshot での書き込み試行を有限にする)。
        return $this->completeAndVerify($configuration, $world, $achievement, $index, $existing, false);
    }

    /**
     * exact Registry が複数見つかった場合 (docs/design.md §10.4)。
     *
     * 物理重複の削除・マージは行わない (Task 05 のスコープ外, docs/spec.md AC-12)。
     *
     * @param  list<RegistryIssue>  $matches
     */
    private function resolveDuplicates(WorldKey $world, Achievement $achievement, array $matches): RegistryResult
    {
        $achievementKey = $achievement->keyString();
        $completed = array_values(array_filter($matches, static fn (RegistryIssue $issue): bool => $issue->done));

        $context = [
            'operation' => 'registry.ensure',
            'worldKey' => $world->value,
            'achievementKey' => $achievementKey,
            'duplicateCount' => count($matches),
            'completedCount' => count($completed),
            'issueKeys' => array_map(static fn (RegistryIssue $issue): string => $issue->issueKey, $matches),
        ];

        if ($completed !== []) {
            // 論理上は 1 つの達成。二重達成として扱わず、自動削除・自動マージもしない。
            $this->logger->warning('registry.duplicate_detected', $context + ['result' => 'already_registered']);

            return RegistryResult::alreadyRegistered($world, $achievement, $completed[0], $matches);
        }

        // どの物理 Issue を正本とするか安全に選べない -> 書き込みを止める。
        $this->logger->warning('registry.duplicate_incomplete', $context + [
            'result' => 'failed',
            'error_type' => RegistryFailureReason::DuplicateIncomplete->value,
        ]);

        return RegistryResult::failed(
            $world,
            $achievement,
            RegistryFailureReason::DuplicateIncomplete,
            'exact Registry が複数あり完了済みが0件のため fail closed とした。新規作成も自動完了も行わない。',
            $matches,
        );
    }

    /**
     * 対象 World Key + Achievement Key に限定した再検索 (docs/design.md §20)。
     *
     * API 側の絞り込みは負荷対策であり、一致判定の根拠にはしない。
     * 返ってきた Issue は必ず PHP 側で完全一致を確認する。
     *
     * @return list<RegistryIssue>
     */
    private function searchExact(ProjectConfiguration $configuration, WorldKey $world, string $achievementKey): array
    {
        $issues = $this->paginator->fetchAll($configuration->projectId, [
            'customField_'.$configuration->recordTypeFieldId => RegistryRecordType::VALUE,
            'customField_'.$configuration->worldKeyFieldId => $world->value,
            'customField_'.$configuration->achievementKeyFieldId => $achievementKey,
        ]);

        return $this->matchRegistries($configuration, $issues, $world, $achievementKey);
    }

    /**
     * 1 Issue を GET して exact 一致を確認する (docs/design.md §10.4 の「再取得確認」)。
     */
    private function fetchExact(
        ProjectConfiguration $configuration,
        WorldKey $world,
        string $achievementKey,
        string $issueKey,
    ): ?RegistryIssue {
        $response = $this->client->get('/api/v2/issues/'.rawurlencode($issueKey));

        $issue = $this->mapper($configuration)->map($response->object());

        if ($issue === null || ! $issue->matches($configuration->projectId, $world->value, $achievementKey)) {
            return null;
        }

        return $issue;
    }

    /**
     * PHP 側の final match (docs/design.md §10.3)。
     *
     * @param  list<array<string, mixed>>  $issues
     * @return list<RegistryIssue>
     */
    private function matchRegistries(
        ProjectConfiguration $configuration,
        array $issues,
        WorldKey $world,
        ?string $achievementKey,
    ): array {
        $mapper = $this->mapper($configuration);
        $matched = [];

        foreach ($issues as $raw) {
            $registry = $mapper->map($raw);

            if ($registry === null) {
                continue;
            }

            // World Key の完全一致。World A の Registry を World B に混ぜない (AC-13)。
            if ($registry->worldKey !== $world->value) {
                continue;
            }

            if ($achievementKey !== null && $registry->achievementKey !== $achievementKey) {
                continue;
            }

            $matched[] = $registry;
        }

        return $matched;
    }

    /**
     * Registry Issue 作成 payload (docs/design.md §10.1, §21)。
     *
     * Project / Issue Type / Priority / Custom Field ID はすべて validated
     * ProjectConfiguration 由来。Adapter payload からは一切受け取らない。
     *
     * @return array<string, mixed>
     */
    private function createPayload(
        ProjectConfiguration $configuration,
        WorldKey $world,
        Achievement $achievement,
    ): array {
        return [
            'projectId' => $configuration->projectId,
            'summary' => RegistrySubject::for($achievement),
            'issueTypeId' => $configuration->registryIssueTypeId,
            'priorityId' => $configuration->registryPriorityId,
            'description' => $this->describe($world, $achievement),
            'customField_'.$configuration->recordTypeFieldId => RegistryRecordType::VALUE,
            'customField_'.$configuration->worldKeyFieldId => $world->value,
            'customField_'.$configuration->achievementKeyFieldId => $achievement->keyString(),
        ];
    }

    /**
     * 説明欄の診断用 metadata (docs/design.md §10.1)。
     *
     * 登録日時は Backlog への保存日時であり、実際の撃破・入手日時ではない
     * (docs/spec.md §5.2)。誤解を避けるため本文に明記する。
     */
    private function describe(WorldKey $world, Achievement $achievement): string
    {
        $lines = [
            'Terraria Achievement Registry (自動生成)',
            '',
            '- Terraria World Key: '.$world->value,
            '- Terraria Key: '.$achievement->keyString(),
            '- Achievement Type: '.$achievement->key->prefix->value,
            '- 登録日時 (Backlog 保存日時): '.gmdate('Y-m-d\TH:i:s\Z'),
        ];

        foreach ($achievement->metadata as $name => $value) {
            if ($value === null || ! is_scalar($value)) {
                continue;
            }

            $lines[] = sprintf('- %s: %s', $name, is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
        }

        $lines[] = '';
        $lines[] = '登録日時は Backlog への保存日時であり、実際の撃破・入手日時ではありません。';

        return implode("\n", $lines);
    }

    private function failed(
        WorldKey $world,
        Achievement $achievement,
        RegistryFailureReason $reason,
        string $detail,
    ): RegistryResult {
        $this->logger->warning('registry.failed', [
            'operation' => 'registry.ensure',
            'worldKey' => $world->value,
            'achievementKey' => $achievement->keyString(),
            'result' => 'failed',
            'error_type' => $reason->value,
            'detail' => $detail,
        ]);

        return RegistryResult::failed($world, $achievement, $reason, $detail);
    }

    private function mapper(ProjectConfiguration $configuration): RegistryIssueMapper
    {
        return $this->mapper ??= new RegistryIssueMapper($configuration);
    }
}
