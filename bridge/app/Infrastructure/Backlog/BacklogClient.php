<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\Exceptions\BacklogAuthenticationException;
use App\Infrastructure\Backlog\Exceptions\BacklogRateLimitException;
use App\Infrastructure\Backlog\Exceptions\BacklogRequestException;
use App\Infrastructure\Backlog\Exceptions\BacklogServerException;
use App\Infrastructure\Backlog\Exceptions\BacklogTransportException;
use App\Infrastructure\Backlog\Support\SecretRedactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nulab Backlog API v2 の共通 HTTP Client (docs/design.md §13)。
 *
 * 設計上の制約:
 * - 認証は `Backlog-API-Key` request header。`apiKey` query parameter は使わない。
 *   URL / access log に秘密情報を残さないため (docs/design.md §13.1)。
 * - connect timeout / request timeout を必ず設定する (docs/design.md §13.2)。
 * - 429 / 5xx / timeout は「0件」でも「成功」でもなく、区別された例外にする
 *   (docs/design.md §13.3, §13.4, §20)。
 * - `X-RateLimit-*` は診断ログ用 metadata として読み取る。
 * - API Key を例外メッセージ・ログへ出さない (docs/design.md §16.2, AC-16)。
 *
 * MVP では永続 retry queue も自動 retry も持たない。失敗は呼び出し元へ返し、
 * 次の periodic / manual reconciliation に委ねる (docs/spec.md §9)。
 */
final class BacklogClient
{
    private readonly SecretRedactor $redactor;

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $connectTimeout,
        private readonly int $requestTimeout,
        private readonly LoggerInterface $logger,
    ) {
        $this->redactor = new SecretRedactor([$this->apiKey]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(string $path, array $query = []): BacklogResponse
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * API Key が設定されているか。値そのものは公開しない。
     */
    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * 例外メッセージ・ログ本文から API Key を取り除く。
     */
    public function redact(string $text): string
    {
        return $this->redactor->redact($text);
    }

    /**
     * Backlog の query parameter 記法を組み立てる。
     *
     * Backlog API は複数値を `projectId[]=1&projectId[]=2` の形式で受け取る。
     * PHP 既定の `http_build_query()` は `projectId[0]=1` を生成し、
     * Backlog 側で認識されないため自前で組み立てる。
     *
     * @param  array<string, mixed>  $query
     */
    public static function buildQueryString(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = rawurlencode((string) $key.'[]').'='.rawurlencode(self::scalarToString($item));
                }

                continue;
            }

            $parts[] = rawurlencode((string) $key).'='.rawurlencode(self::scalarToString($value));
        }

        return implode('&', $parts);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function send(string $method, string $path, array $query): BacklogResponse
    {
        $queryString = self::buildQueryString($query);
        $url = $queryString === '' ? $path : $path.'?'.$queryString;

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->withHeaders([
                    // docs/design.md §13.1: API Key は header で送る。
                    'Backlog-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ])
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->requestTimeout)
                ->send($method, $url);
        } catch (ConnectionException $exception) {
            // 元例外は previous に持たせない (秘密情報混入の回避)。
            $message = $this->redact(sprintf(
                'Backlog API への接続に失敗した (%s %s): %s',
                $method,
                $path,
                $exception->getMessage(),
            ));

            $this->logFailure($method, $path, null, 'backlog.transport_error', null);

            throw new BacklogTransportException($message);
        } catch (Throwable $exception) {
            $message = $this->redact(sprintf(
                'Backlog API 呼び出しが失敗した (%s %s): %s',
                $method,
                $path,
                $exception->getMessage(),
            ));

            $this->logFailure($method, $path, null, 'backlog.api_error', null);

            throw new BacklogApiException($message);
        }

        return $this->interpret($method, $path, $response);
    }

    private function interpret(string $method, string $path, Response $response): BacklogResponse
    {
        $status = $response->status();
        $rateLimit = RateLimitStatus::fromResponse($response);

        if ($status === 429) {
            $this->logFailure($method, $path, $status, 'backlog.rate_limited', $rateLimit);

            throw new BacklogRateLimitException(
                sprintf('Backlog API がレート制限を返した (%s %s, HTTP 429).', $method, $path),
                $rateLimit,
            );
        }

        if ($status === 401 || $status === 403) {
            $this->logFailure($method, $path, $status, 'backlog.authentication_failed', $rateLimit);

            throw new BacklogAuthenticationException(
                sprintf('Backlog API の認証・権限エラー (%s %s, HTTP %d).', $method, $path, $status),
                $status,
            );
        }

        if ($status >= 500) {
            $this->logFailure($method, $path, $status, 'backlog.server_error', $rateLimit);

            throw new BacklogServerException(
                sprintf('Backlog API がサーバーエラーを返した (%s %s, HTTP %d).', $method, $path, $status),
                $status,
            );
        }

        if ($status >= 400) {
            $this->logFailure($method, $path, $status, 'backlog.request_error', $rateLimit);

            throw new BacklogRequestException(
                sprintf('Backlog API がエラーを返した (%s %s, HTTP %d).', $method, $path, $status),
                $status,
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            $this->logFailure($method, $path, $status, 'backlog.unexpected_payload', $rateLimit);

            throw new BacklogApiException(
                sprintf('Backlog API のレスポンスを JSON として解釈できない (%s %s).', $method, $path),
                $status,
                'backlog.unexpected_payload',
            );
        }

        $this->logger->debug('backlog.request', array_merge([
            'operation' => 'backlog.request',
            'http_method' => $method,
            'path' => $path,
            'http_status' => $status,
            'result' => 'ok',
        ], $rateLimit->toLogContext()));

        return new BacklogResponse($status, $json, $rateLimit);
    }

    private function logFailure(
        string $method,
        string $path,
        ?int $status,
        string $errorType,
        ?RateLimitStatus $rateLimit,
    ): void {
        // 秘密情報を含む header / URL query は出力しない (docs/spec.md §10)。
        $this->logger->warning('backlog.request', array_merge([
            'operation' => 'backlog.request',
            'http_method' => $method,
            'path' => $path,
            'http_status' => $status,
            'result' => 'failed',
            'error_type' => $errorType,
        ], $rateLimit?->toLogContext() ?? []));
    }

    private static function scalarToString(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        throw new BacklogApiException('Backlog API の query parameter に scalar 以外を指定できない.');
    }
}
