<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Domain\Mapping\CompletionFailureReason;
use App\Domain\Mapping\CompletionResult;
use App\Domain\Mapping\MappingCandidate;
use App\Domain\Mapping\MappingIndex;
use App\Domain\Mapping\MappingIssue;
use App\Domain\Mapping\MappingRepository;
use App\Domain\Snapshot\WorldKey;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Backlog を攻略課題 Mapping の読み取り元 / 更新先として使う実装
 * (docs/design.md §11, §12, §20, §21 / AC-06, AC-07, AC-10, AC-14, AC-15, AC-19)。
 *
 * 対象 Project には **このシステムと無関係な研修課題が同居する**。したがって
 * 本クラスの最優先の不変条件は「一般課題を推測で更新しない」ことである。
 *
 * - Mapping と断定できない Issue は silent ignore か診断ログのみ。状態は変えない。
 * - 完了済みの攻略課題・研修課題は scan 対象から外す。コメントも属性補完もしない。
 * - PATCH の前に必ず current issue を再取得し、Project ID / 未完了 / Record Type /
 *   World Key / Terraria Key をすべて再確認する。
 * - 検索障害を「該当 0 件」と読み替えない。例外は呼び出し元へ伝播させる。
 * - update failure を success 扱いしない。
 * - Project / Custom Field / Status ID は validated ProjectConfiguration からのみ取る。
 *   Adapter payload 由来の値は一切使わない (docs/design.md §16.3)。
 *
 * Mapping は Registry と違い新規作成・属性補完を行わない (docs/specs/backlog-mapping.md
 * 「Mapping の自動作成/補完は対象外」) ため、cross-process lock は取らない。
 * 同時実行で二重に PATCH されても「完了へ揃える」冪等操作であり、再取得による
 * 状態確認で重複 PATCH は抑止される。
 */
final class BacklogMappingRepository implements MappingRepository
{
    private ?MappingIssueMapper $mapper = null;

    public function __construct(
        private readonly ProjectConfigurationRepository $projects,
        private readonly IssueListPaginator $paginator,
        private readonly BacklogClient $client,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    /**
     * 対象 Project / World の未完了 Mapping を一度だけ全ページ取得して index 化する
     * (docs/design.md §11, §20)。
     *
     * Issue List query は対象 Project ID だけに限定する。Custom Field の文字列
     * filter は Backlog 側では部分一致であり exact lookup ではないため、絞り込みに
     * 使うと取りこぼす可能性がある。絞り込みは PHP 側の final match に一本化する。
     *
     * 呼び直せば post-hoc Mapping (Registry 登録後に追加された Mapping) も
     * reopen された課題も、その時点の未完了 Mapping として再取得される (AC-06, AC-15)。
     */
    public function loadIncompleteIndex(WorldKey $world): MappingIndex
    {
        $configuration = $this->projects->resolve();

        // 検索障害は例外として伝播させ、呼び出し側に「0 件」と解釈させない。
        $issues = $this->paginator->fetchAll($configuration->projectId);

        $index = new MappingIndex($world, $configuration->projectId);

        $ignored = [
            'completed' => 0,
            'other_world' => 0,
        ];
        $rejected = [];

        foreach ($issues as $raw) {
            $candidate = $this->mapper($configuration)->map($raw);

            if (! $candidate->isAccepted()) {
                $rejection = $candidate->rejection;
                $rejected[$rejection->value] = ($rejected[$rejection->value] ?? 0) + 1;

                if ($rejection->shouldLog()) {
                    // 更新はしない。診断情報だけ残す (docs/design.md §11)。
                    $this->logger->warning($rejection->value, [
                        'operation' => 'mapping.scan',
                        'world_key' => $world->value,
                        'projectId' => $configuration->projectId,
                    ] + $candidate->toLogContext());
                }

                continue;
            }

            $mapping = $candidate->issue;

            // 完了済みの攻略課題・研修課題は同期対象から外す (AC-19)。
            if ($mapping->done) {
                $ignored['completed']++;

                continue;
            }

            // World Key の完全一致。他ワールドの Mapping を混ぜない (AC-13)。
            if ($mapping->worldKey !== $world->value) {
                $ignored['other_world']++;

                continue;
            }

            $index->add($mapping);
        }

        $this->logger->info('mapping.index_loaded', [
            'operation' => 'mapping.index_loaded',
            'world_key' => $world->value,
            'projectId' => $configuration->projectId,
            'scannedIssues' => count($issues),
            'mappingIssues' => $index->count(),
            'achievementKeys' => count($index->achievementKeys()),
            'ignored' => $ignored + $rejected,
        ]);

        return $index;
    }

    /**
     * 攻略課題を configured Done Status へ更新する (docs/design.md §21 Mapping 課題)。
     *
     * ```text
     * GET current issue
     *   -> Project + 未完了 + exact Mapping + Record Type 空欄を再確認
     * PATCH /api/v2/issues/{issueKey}
     *   statusId = configured done status
     * ```
     *
     * scan から PATCH までの間に利用者が課題を完了した / Mapping を外した /
     * 別 Achievement へ張り替えた可能性があるため、再取得を省略しない。
     */
    public function complete(MappingIssue $mapping): CompletionResult
    {
        $configuration = $this->projects->resolve();

        try {
            $current = $this->fetch($configuration, $mapping->issueKey);
        } catch (BacklogApiException $exception) {
            // 状態を確認できないまま PATCH しない。次回 reconciliation に委ねる。
            return $this->fail(
                $mapping,
                CompletionFailureReason::LookupFailed,
                'PATCH 前の再取得に失敗した: '.$this->client->redact($exception->getMessage()),
            );
        }

        $guard = $this->guard($configuration, $mapping, $current);

        if ($guard !== null) {
            return $guard;
        }

        try {
            $response = $this->client->patch(
                '/api/v2/issues/'.rawurlencode($mapping->issueKey),
                // 状態 ID は validated config から。表示名や固定値を前提にしない。
                ['statusId' => $configuration->doneStatusId],
            );
        } catch (BacklogApiException $exception) {
            return $this->afterFailedWrite($configuration, $mapping, $exception);
        }

        // PATCH 応答が完了を示していればそれで確定。示していなければ再取得で確認する。
        // 応答だけを根拠に success と断定しない (docs/design.md §13.4)。
        $updated = $this->mapper($configuration)->map($response->object());

        if ($updated->isAccepted() && $updated->issue->done) {
            return $this->succeed($mapping);
        }

        return $this->verify(
            $configuration,
            $mapping,
            CompletionFailureReason::VerificationFailed,
            'PATCH 後に完了状態を確認できなかった。',
        );
    }

    /**
     * PATCH 直前の再確認 (docs/design.md §12.1, §21)。
     *
     * 1 つでも崩れていたら更新しない。戻り値が null の場合のみ PATCH してよい。
     */
    private function guard(
        ProjectConfiguration $configuration,
        MappingIssue $mapping,
        MappingCandidate $current,
    ): ?CompletionResult {
        if (! $current->isAccepted()) {
            // Mapping 属性の解除 / 未知の Record Type への変更 / 対象外 Project など。
            return $this->skip(
                $mapping,
                CompletionFailureReason::MappingChanged,
                sprintf('再取得した課題は Mapping として扱えない (%s)。更新しない。', $current->rejection->value),
            );
        }

        $fresh = $current->issue;

        if (! $fresh->isSameIssue($mapping)) {
            return $this->skip(
                $mapping,
                CompletionFailureReason::MappingMismatch,
                '再取得した課題が対象と同一 Issue ではない。更新しない。',
            );
        }

        // 既に完了 -> PATCH しない。コメントも追加しない (AC-11, docs/design.md §12.1, §12.2)。
        if ($fresh->done) {
            $this->logger->info('issue.completion_skipped', [
                'operation' => 'mapping.complete',
                'result' => 'already_completed',
            ] + $fresh->toLogContext());

            return CompletionResult::alreadyCompleted($mapping);
        }

        // Project / World Key / Terraria Key の完全一致 (AC-13, AC-15)。
        if (! $fresh->matches($configuration->projectId, $mapping->worldKey, $mapping->achievementKey)) {
            return $this->skip(
                $mapping,
                CompletionFailureReason::MappingMismatch,
                '再取得した課題の Project / World Key / Terraria Key が対象と一致しない。更新しない。',
            );
        }

        return null;
    }

    /**
     * PATCH が失敗した場合 (docs/specs/backlog-sync.md「更新応答が不明な場合も再照合」)。
     *
     * - 明確な失敗 (4xx) は書き込まれていない。再取得せず failed。
     * - timeout / 5xx / 429 は適用有無が不明。再取得して現在状態で判定する。
     *
     * いずれの場合も success 扱いしない。
     */
    private function afterFailedWrite(
        ProjectConfiguration $configuration,
        MappingIssue $mapping,
        BacklogApiException $exception,
    ): CompletionResult {
        $detail = $this->client->redact($exception->getMessage());

        if (! $exception->isRetriable()) {
            return $this->fail($mapping, CompletionFailureReason::WriteFailed, '課題の完了更新に失敗した: '.$detail);
        }

        return $this->verify(
            $configuration,
            $mapping,
            CompletionFailureReason::WriteResultUnknown,
            '課題の完了更新の結果が不明で、再取得でも完了を確認できなかった: '.$detail,
        );
    }

    /**
     * 現在状態を再取得し、完了していれば成功として確定する。
     */
    private function verify(
        ProjectConfiguration $configuration,
        MappingIssue $mapping,
        CompletionFailureReason $reason,
        string $detail,
    ): CompletionResult {
        try {
            $current = $this->fetch($configuration, $mapping->issueKey);
        } catch (BacklogApiException $exception) {
            return $this->fail($mapping, $reason, $detail.' / 再取得も失敗した: '.$this->client->redact($exception->getMessage()));
        }

        if (
            $current->isAccepted()
            && $current->issue->done
            && $current->issue->isSameIssue($mapping)
            && $current->issue->matches($configuration->projectId, $mapping->worldKey, $mapping->achievementKey)
        ) {
            return $this->succeed($mapping);
        }

        return $this->fail($mapping, $reason, $detail);
    }

    /**
     * 1 Issue を GET して Mapping として評価する (docs/design.md §21)。
     */
    private function fetch(ProjectConfiguration $configuration, string $issueKey): MappingCandidate
    {
        $response = $this->client->get('/api/v2/issues/'.rawurlencode($issueKey));

        return $this->mapper($configuration)->map($response->object());
    }

    private function succeed(MappingIssue $mapping): CompletionResult
    {
        $result = CompletionResult::completed($mapping);

        $this->logger->info('issue.completed', [
            'operation' => 'mapping.complete',
        ] + $result->toLogContext());

        return $result;
    }

    private function skip(MappingIssue $mapping, CompletionFailureReason $reason, string $detail): CompletionResult
    {
        $result = CompletionResult::skipped($mapping, $reason, $detail);

        $this->logger->info('issue.completion_skipped', [
            'operation' => 'mapping.complete',
        ] + $result->toLogContext());

        return $result;
    }

    private function fail(MappingIssue $mapping, CompletionFailureReason $reason, string $detail): CompletionResult
    {
        $result = CompletionResult::failed($mapping, $reason, $detail);

        $this->logger->warning('issue.completion_failed', [
            'operation' => 'mapping.complete',
        ] + $result->toLogContext());

        return $result;
    }

    private function mapper(ProjectConfiguration $configuration): MappingIssueMapper
    {
        return $this->mapper ??= new MappingIssueMapper($configuration);
    }
}
