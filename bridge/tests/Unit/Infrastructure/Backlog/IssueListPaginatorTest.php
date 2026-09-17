<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\Exceptions\BacklogRateLimitException;
use App\Infrastructure\Backlog\Exceptions\BacklogServerException;
use App\Infrastructure\Backlog\Exceptions\BacklogTransportException;
use App\Infrastructure\Backlog\IssueListPaginator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/design.md §13.2 / §20 (Backlog Search / Pagination Algorithm) の検証。
 *
 * 1ページで打ち切る実装は Registry / Mapping の取りこぼし、ひいては重複登録に
 * 直結するため、全ページ取得と「障害を0件と扱わない」ことを固定する。
 */
final class IssueListPaginatorTest extends TestCase
{
    private const BASE_URL = 'https://backlog.test';

    private const PROJECT_ID = 4242;

    private function paginator(int $pageSize = 100, int $maxPages = 200): IssueListPaginator
    {
        $client = new BacklogClient(
            http: $this->app->make(HttpFactory::class),
            baseUrl: self::BASE_URL,
            apiKey: 'test-key',
            connectTimeout: 2,
            requestTimeout: 8,
            logger: new RecordingLogger,
        );

        return new IssueListPaginator($client, $pageSize, $maxPages);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function issues(int $count, int $startId): array
    {
        $issues = [];

        for ($i = 0; $i < $count; $i++) {
            $issues[] = ['id' => $startId + $i, 'issueKey' => 'TRAINING_YOSHIZUMI-'.($startId + $i)];
        }

        return $issues;
    }

    #[Test]
    public function it_fetches_every_page_not_just_the_first(): void
    {
        Http::fakeSequence()
            ->push($this->issues(100, 1), 200)
            ->push($this->issues(100, 101), 200)
            ->push($this->issues(37, 201), 200);

        $issues = $this->paginator()->fetchAll(self::PROJECT_ID);

        $this->assertCount(237, $issues);
        $this->assertSame(1, $issues[0]['id']);
        $this->assertSame(237, $issues[236]['id']);
        Http::assertSentCount(3);

        $offsets = [];

        Http::assertSent(function (Request $request) use (&$offsets): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $offsets[] = $query['offset'] ?? null;

            $this->assertSame('100', $query['count'] ?? null);
            // 対象 Project へ限定していること (docs/design.md §13.2)。
            $this->assertSame([(string) self::PROJECT_ID], $query['projectId'] ?? null);

            return true;
        });

        $this->assertSame(['0', '100', '200'], $offsets);
    }

    #[Test]
    public function it_requests_another_page_when_a_page_is_exactly_full(): void
    {
        Http::fakeSequence()
            ->push($this->issues(100, 1), 200)
            ->push([], 200);

        $issues = $this->paginator()->fetchAll(self::PROJECT_ID);

        $this->assertCount(100, $issues);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_stops_on_the_first_short_page(): void
    {
        Http::fakeSequence()->push($this->issues(3, 1), 200);

        $issues = $this->paginator()->fetchAll(self::PROJECT_ID);

        $this->assertCount(3, $issues);
        Http::assertSentCount(1);
    }

    #[Test]
    public function it_passes_additional_filters_through(): void
    {
        Http::fakeSequence()->push([], 200);

        $this->paginator()->fetchAll(self::PROJECT_ID, ['statusId' => [1, 2, 3]]);

        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            $this->assertSame(['1', '2', '3'], $query['statusId'] ?? null);

            return true;
        });
    }

    #[Test]
    public function it_rejects_filters_that_would_break_pagination(): void
    {
        Http::fake();

        foreach (['projectId', 'count', 'offset'] as $reserved) {
            try {
                $this->paginator()->fetchAll(self::PROJECT_ID, [$reserved => 1]);
                $this->fail(sprintf('"%s" の上書きが拒否されなかった。', $reserved));
            } catch (BacklogApiException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function it_does_not_treat_a_server_error_on_the_first_page_as_zero_results(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response('boom', 500)]);

        $this->expectException(BacklogServerException::class);

        // 空配列を返してはならない。「検索できなかった」を「該当0件」と
        // 解釈すると Registry の重複作成につながる (docs/design.md §20)。
        $this->paginator()->fetchAll(self::PROJECT_ID);
    }

    #[Test]
    public function it_does_not_return_a_partial_result_when_a_later_page_fails(): void
    {
        Http::fakeSequence()
            ->push($this->issues(100, 1), 200)
            ->push([], 503);

        $this->expectException(BacklogServerException::class);

        $this->paginator()->fetchAll(self::PROJECT_ID);
    }

    #[Test]
    public function it_does_not_treat_a_rate_limited_page_as_the_end_of_the_list(): void
    {
        Http::fakeSequence()
            ->push($this->issues(100, 1), 200)
            ->push([], 429);

        $this->expectException(BacklogRateLimitException::class);

        $this->paginator()->fetchAll(self::PROJECT_ID);
    }

    #[Test]
    public function it_does_not_treat_a_timeout_as_the_end_of_the_list(): void
    {
        Http::fake(function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(BacklogTransportException::class);

        $this->paginator()->fetchAll(self::PROJECT_ID);
    }

    #[Test]
    public function it_fails_closed_when_the_page_limit_is_exceeded(): void
    {
        Http::fake([self::BASE_URL.'/*' => Http::response($this->issues(2, 1), 200)]);

        try {
            $this->paginator(pageSize: 2, maxPages: 3)->fetchAll(self::PROJECT_ID);
            $this->fail('上限超過が例外にならなかった。');
        } catch (BacklogApiException $exception) {
            $this->assertSame('backlog.pagination_limit_exceeded', $exception->errorType());
        }

        Http::assertSentCount(3);
    }
}
