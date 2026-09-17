<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Backlog\BacklogClient;
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
 * Task 05 で追加した write 系 (`post` / `patch`) の検証 (docs/design.md §13, §21)。
 *
 * Backlog API v2 の write endpoint は form-urlencoded を受け取る。
 * 失敗は read と同じ例外方針で区別し、成功扱いしないことを固定する。
 */
final class BacklogClientWriteTest extends TestCase
{
    private const BASE_URL = 'https://backlog.test';

    private const API_KEY = 'write-secret-api-key-abcdef123456';

    private function client(): BacklogClient
    {
        return new BacklogClient(
            http: $this->app->make(HttpFactory::class),
            baseUrl: self::BASE_URL,
            apiKey: self::API_KEY,
            connectTimeout: 2,
            requestTimeout: 8,
            logger: new RecordingLogger,
        );
    }

    #[Test]
    public function it_sends_post_as_form_urlencoded_with_the_api_key_header(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['id' => 1, 'issueKey' => 'X-1'], 201)]);

        $response = $this->client()->post('/api/v2/issues', [
            'projectId' => 4242,
            'summary' => '[Terraria Registry] Rod of Discord',
            'customField_123456' => 'registry',
        ]);

        $this->assertSame(201, $response->status);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertTrue($request->isForm());
            $this->assertSame(self::API_KEY, $request->header('Backlog-API-Key')[0]);
            // 秘密情報を URL へ載せない (docs/design.md §13.1)。
            $this->assertStringNotContainsString(self::API_KEY, $request->url());
            $this->assertSame('4242', $request->data()['projectId']);
            $this->assertSame('registry', $request->data()['customField_123456']);

            return true;
        });
    }

    #[Test]
    public function it_sends_patch_with_the_status_id(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response(['id' => 1], 200)]);

        $this->client()->patch('/api/v2/issues/TRAINING_YOSHIZUMI-1', ['statusId' => 4]);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('PATCH', $request->method());
            $this->assertSame(self::BASE_URL.'/api/v2/issues/TRAINING_YOSHIZUMI-1', $request->url());
            $this->assertSame('4', $request->data()['statusId']);

            return true;
        });
    }

    #[Test]
    public function it_does_not_treat_a_write_timeout_as_success(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        try {
            $this->client()->post('/api/v2/issues', ['projectId' => 1]);
            $this->fail('write timeout が例外にならなかった。');
        } catch (BacklogTransportException $exception) {
            $this->assertTrue($exception->isRetriable());
            $this->assertSame('backlog.transport_error', $exception->errorType());
        }
    }

    /**
     * @return list<array{0: int, 1: class-string<\Throwable>}>
     */
    public static function writeFailures(): array
    {
        return [
            [429, BacklogRateLimitException::class],
            [503, BacklogServerException::class],
            [400, BacklogRequestException::class],
        ];
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    #[Test]
    #[DataProvider('writeFailures')]
    public function it_distinguishes_write_failures_by_status(int $status, string $expected): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response([], $status)]);

        $this->assertWriteThrows($expected);
    }

    /**
     * @param  class-string<\Throwable>  $expected
     */
    private function assertWriteThrows(string $expected): void
    {
        try {
            $this->client()->post('/api/v2/issues', ['projectId' => 1]);
            $this->fail($expected.' が投げられなかった。');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($expected, $exception);
            $this->assertStringNotContainsString(self::API_KEY, $exception->getMessage());
        }
    }
}
