<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\Exceptions\BacklogAuthenticationException;
use App\Infrastructure\Backlog\Exceptions\BacklogRateLimitException;
use App\Infrastructure\Backlog\Exceptions\BacklogRequestException;
use App\Infrastructure\Backlog\Exceptions\BacklogServerException;
use App\Infrastructure\Backlog\Exceptions\BacklogTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/design.md §13 (Backlog API Client) の検証。
 */
final class BacklogClientTest extends TestCase
{
    private const API_KEY = 'super-secret-backlog-api-key-0123456789';

    private const BASE_URL = 'https://backlog.test';

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger;
    }

    private function client(int $connectTimeout = 2, int $requestTimeout = 8): BacklogClient
    {
        return new BacklogClient(
            http: $this->app->make(HttpFactory::class),
            baseUrl: self::BASE_URL,
            apiKey: self::API_KEY,
            connectTimeout: $connectTimeout,
            requestTimeout: $requestTimeout,
            logger: $this->logger,
        );
    }

    #[Test]
    public function it_sends_the_api_key_as_a_header_and_never_in_the_url(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['id' => 1], 200)]);

        $this->client()->get('/api/v2/users/myself');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::API_KEY, $request->header('Backlog-API-Key')[0]);
            // docs/design.md §13.1: apiKey query parameter は使わない。
            $this->assertStringNotContainsString(self::API_KEY, $request->url());
            $this->assertStringNotContainsString('apiKey', $request->url());

            return true;
        });
    }

    #[Test]
    public function it_applies_connect_and_request_timeouts(): void
    {
        $captured = [];

        Http::fake(function ($request, $options) use (&$captured) {
            $captured = $options;

            return Http::response([], 200);
        });

        $this->client(connectTimeout: 2, requestTimeout: 8)->get('/api/v2/users/myself');

        // docs/design.md §13.2 の既定値。
        $this->assertSame(2, $captured['connect_timeout'] ?? null);
        $this->assertSame(8, $captured['timeout'] ?? null);
    }

    #[Test]
    public function it_exposes_rate_limit_headers_for_diagnostics(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response([], 200, [
                'X-RateLimit-Limit' => '150',
                'X-RateLimit-Remaining' => '142',
                'X-RateLimit-Reset' => '1605484860',
            ]),
        ]);

        $response = $this->client()->get('/api/v2/users/myself');

        $this->assertSame(150, $response->rateLimit->limit);
        $this->assertSame(142, $response->rateLimit->remaining);
        $this->assertSame(1605484860, $response->rateLimit->reset);
    }

    #[Test]
    public function it_does_not_treat_429_as_success(): void
    {
        Http::fake([
            self::BASE_URL.'/*' => Http::response(['errors' => []], 429, [
                'X-RateLimit-Reset' => '1605484860',
            ]),
        ]);

        try {
            $this->client()->get('/api/v2/issues');
            $this->fail('429 が例外にならなかった。');
        } catch (BacklogRateLimitException $exception) {
            $this->assertSame(429, $exception->status());
            $this->assertTrue($exception->isRetriable());
            // docs/design.md §13.3: X-RateLimit-Reset を診断ログに残せること。
            $this->assertSame(1605484860, $exception->rateLimit()->reset);
        }
    }

    #[Test]
    public function it_does_not_treat_5xx_as_success(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response('gateway error', 503)]);

        $this->expectException(BacklogServerException::class);

        $this->client()->get('/api/v2/issues');
    }

    #[Test]
    public function it_does_not_treat_timeout_as_success(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out after 8000 milliseconds');
        });

        try {
            $this->client()->get('/api/v2/issues');
            $this->fail('timeout が例外にならなかった。');
        } catch (BacklogTransportException $exception) {
            $this->assertTrue($exception->isRetriable());
            $this->assertSame('backlog.transport_error', $exception->errorType());
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function authenticationStatuses(): array
    {
        return ['401' => [401], '403' => [403]];
    }

    #[Test]
    #[DataProvider('authenticationStatuses')]
    public function it_raises_an_authentication_error_for_401_and_403(int $status): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['errors' => []], $status)]);

        try {
            $this->client()->get('/api/v2/users/myself');
            $this->fail(sprintf('HTTP %d が例外にならなかった。', $status));
        } catch (BacklogAuthenticationException $exception) {
            $this->assertSame($status, $exception->status());
            $this->assertFalse($exception->isRetriable());
        }
    }

    #[Test]
    public function it_does_not_treat_a_404_as_an_empty_result(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['errors' => []], 404)]);

        try {
            $this->client()->get('/api/v2/projects/TRAINING_YOSHIZUMI');
            $this->fail('404 が例外にならなかった。');
        } catch (BacklogRequestException $exception) {
            $this->assertTrue($exception->isNotFound());
        }
    }

    #[Test]
    public function it_rejects_a_non_json_payload_instead_of_returning_nothing(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response('<html>maintenance</html>', 200)]);

        $this->expectException(BacklogApiException::class);

        $this->client()->get('/api/v2/issues');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function failureModes(): array
    {
        return [
            'rate limit' => ['rate_limit'],
            'server error' => ['server_error'],
            'unauthorized' => ['unauthorized'],
            'timeout with a leaking transport message' => ['leaking_timeout'],
        ];
    }

    #[Test]
    #[DataProvider('failureModes')]
    public function it_never_leaks_the_api_key_in_exception_messages(string $mode): void
    {
        match ($mode) {
            'rate_limit' => Http::fake([self::BASE_URL.'/*' => Http::response([], 429)]),
            'server_error' => Http::fake([self::BASE_URL.'/*' => Http::response([], 500)]),
            'unauthorized' => Http::fake([self::BASE_URL.'/*' => Http::response([], 401)]),
            // cURL のメッセージに秘密情報が混ざった最悪ケースを模す。
            default => Http::fake(function (): never {
                throw new ConnectionException('cURL error 28 while sending Backlog-API-Key: '.self::API_KEY);
            }),
        };

        try {
            $this->client()->get('/api/v2/issues');
            $this->fail(sprintf('%s が例外にならなかった。', $mode));
        } catch (BacklogApiException $exception) {
            $this->assertStringNotContainsString(
                self::API_KEY,
                $exception->getMessage(),
                sprintf('%s の例外メッセージに API Key が含まれている。', $mode),
            );
            $this->assertStringNotContainsString(self::API_KEY, (string) $exception);
            $this->assertStringNotContainsString(self::API_KEY, $this->logger->dump());
        }
    }

    #[Test]
    public function it_never_leaks_the_api_key_into_logs(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['ok' => true], 200, [
            'X-RateLimit-Remaining' => '10',
        ])]);

        $this->client()->get('/api/v2/users/myself');

        $this->assertNotSame('[]', $this->logger->dump(), '診断ログが1件も出ていない。');
        $this->assertStringNotContainsString(self::API_KEY, $this->logger->dump());
        // header そのものをログへ出さない (docs/spec.md §10)。
        $this->assertStringNotContainsString('Backlog-API-Key', $this->logger->dump());
    }

    #[Test]
    public function it_redacts_the_api_key_from_arbitrary_text(): void
    {
        $client = $this->client();

        $this->assertStringNotContainsString(
            self::API_KEY,
            $client->redact('leaked: '.self::API_KEY),
        );
        $this->assertStringNotContainsString(
            self::API_KEY,
            $client->redact('leaked: '.rawurlencode(self::API_KEY)),
        );
    }

    #[Test]
    public function it_builds_backlog_style_array_query_parameters(): void
    {
        $query = BacklogClient::buildQueryString([
            'projectId' => [42, 43],
            'count' => 100,
            'offset' => 0,
        ]);

        // Backlog は projectId[]=42 形式を期待する。http_build_query の
        // projectId[0]=42 では絞り込まれない。
        $this->assertSame('projectId%5B%5D=42&projectId%5B%5D=43&count=100&offset=0', $query);
    }
}
