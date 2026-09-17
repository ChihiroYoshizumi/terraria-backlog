<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adapter → PHP の Bearer Token 認証 (docs/design.md §6.1 / §16.1, AC-16)。
 *
 * 認証は Snapshot の処理を開始する前に行う。失敗時は Registry / Mapping を
 * 一切触らない。Token 値そのものはログにも response にも出さない。
 */
final class EnsureAdapterToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('terraria.adapter_token');

        if (! is_string($expected) || $expected === '') {
            // fail closed (docs/design.md §18.4)。Token 未設定で endpoint を開放しない。
            Log::error('snapshot.auth.misconfigured', [
                'path' => $request->path(),
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'bridge.misconfigured',
                    'message' => 'TERRARIA_ADAPTER_TOKEN is not configured.',
                ],
            ], 503);
        }

        $presented = $this->bearerToken($request);

        if ($presented === null || ! hash_equals($expected, $presented)) {
            Log::warning('snapshot.auth.rejected', [
                'path' => $request->path(),
                'reason' => $presented === null ? 'missing_bearer_token' : 'invalid_bearer_token',
            ]);

            return new JsonResponse([
                'error' => [
                    'code' => 'auth.unauthorized',
                    'message' => 'A valid adapter bearer token is required.',
                ],
            ], 401, ['WWW-Authenticate' => 'Bearer']);
        }

        return $next($request);
    }

    /**
     * `Authorization: Bearer <token>` だけを受理する。
     * query string 等に token を載せる経路は作らない (ログ漏洩の防止)。
     */
    private function bearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (! is_string($header)) {
            return null;
        }

        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
