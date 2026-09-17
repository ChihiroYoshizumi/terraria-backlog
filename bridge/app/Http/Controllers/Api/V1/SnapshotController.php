<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Snapshot\SnapshotOutcome;
use App\Domain\Snapshot\SnapshotProcessor;
use App\Domain\Snapshot\SnapshotRejectedException;
use App\Domain\Snapshot\SnapshotRequestParser;
use App\Domain\Snapshot\WorldSnapshot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAdapterToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /api/v1/worlds/{worldKey}/snapshots (docs/design.md §6.1)。
 *
 * 認証は {@see EnsureAdapterToken} が route middleware として
 * 先に実施する。ここから先は「認証済みの Adapter からの入力」だけを扱う。
 *
 * FormRequest を使わず raw body を自前で decode するのは、
 * docs/design.md §6.4 が要求する「型 coercion を行わない」検証のためである。
 * Laravel の validation rule (`integer` / `boolean` 等) は文字列を受理してしまう。
 */
final class SnapshotController extends Controller
{
    /**
     * Parser / Processor は constructor ではなく method injection で受け取る。
     * Laravel は Route ごとに controller instance を memo 化するため、
     * constructor injection だと設定変更が反映されない実行系が生まれる。
     */
    public function store(
        Request $request,
        SnapshotRequestParser $parser,
        SnapshotProcessor $processor,
        string $worldKey,
    ): JsonResponse {
        try {
            $snapshot = $parser->parse($worldKey, $request->getContent());
        } catch (SnapshotRejectedException $rejection) {
            Log::warning('snapshot.rejected', [
                'world_key' => $worldKey,
                'code' => $rejection->rejectionCode->value,
                'detail' => $rejection->getMessage(),
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => $rejection->rejectionCode->value,
                    'message' => $rejection->getMessage(),
                ],
            ], $rejection->httpStatus());
        }

        $this->logInvalidItems($snapshot);

        $result = $processor->process($snapshot);

        // docs/design.md §15.3 / §18.2: 再照合を完了できず返せる通知も無い場合は
        // 「再試行可能な非成功応答」を返す。Adapter はこれで復旧待ちフラグを立て、
        // 次の periodic / manual に recoveryPending を付けて再送する (§15.2)。
        // 成功 ACK を 200 で捏造しない (AC-09)。
        if ($result->outcome === SnapshotOutcome::RetriableFailure) {
            Log::warning('snapshot.retry_later', [
                ...$snapshot->logContext(),
                'result' => $result->outcome->value,
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'reconciliation.retry_later',
                    'message' => 'Snapshot の再照合を完了できなかった。次の reconciliation で再試行する。',
                ],
            ], $result->httpStatus());
        }

        // docs/design.md §6.5 / contracts/snapshot-response-v1.schema.json:
        // notification-only。Achievement Key / Registry 結果 / Backlog Issue Key /
        // Mapping 結果をこの response に追加してはならない。
        return new JsonResponse([
            'requestId' => $snapshot->requestId,
            'worldKey' => $snapshot->worldKey()->value,
            'notifications' => $result->toArray(),
        ]);
    }

    /**
     * Item 単位で無視した入力を診断ログに残す (docs/design.md §6.4, §18.3)。
     * Snapshot 全体は失敗させない。
     */
    private function logInvalidItems(WorldSnapshot $snapshot): void
    {
        foreach ($snapshot->rejectedItems() as $rejected) {
            Log::info('item.invalid_skipped', [
                ...$snapshot->logContext(),
                'chest_x' => $rejected['chest']->x,
                'chest_y' => $rejected['chest']->y,
                ...$rejected['item']->diagnosticContext(),
            ]);
        }
    }
}
