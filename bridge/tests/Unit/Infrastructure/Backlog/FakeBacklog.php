<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Registry Repository のテスト用 Backlog stub。
 *
 * 実 Backlog へは接続しない。意図的に「意地悪な」API を演じる。
 *
 * - Issue List は query の絞り込み (projectId / customField_*) を **無視して全件返す**。
 *   Backlog の文字列検索を exact lookup とみなさない実装 (docs/design.md §10.3) を
 *   検証するため、PHP 側の final match が唯一の絞り込みになる状況を作る。
 * - `interceptNext()` で timeout / エラー応答を 1 回だけ差し込める。
 * - 送信されたリクエストは自前で記録する。例外を投げるケースでは Laravel 側の
 *   recorded に残らないため。
 */
final class FakeBacklog
{
    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, needle: string, handler: Closure, used: bool}> */
    private array $interceptors = [];

    private int $nextId = 1000;

    /**
     * @param  array{record_type: int, world_key: int, achievement_key: int}  $fieldIds
     */
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $projectId,
        private readonly string $projectKey,
        private readonly array $fieldIds,
        private readonly int $doneStatusId,
        private readonly int $openStatusId,
    ) {}

    public function install(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            return $this->handle($request);
        });
    }

    /**
     * 次に来る (method, path に needle を含む) リクエストを 1 回だけ差し替える。
     *
     * handler は例外を投げてもよい (timeout の模擬)。null を返すと通常処理を続ける。
     */
    public function interceptNext(string $method, string $needle, Closure $handler): void
    {
        $this->interceptors[] = ['method' => $method, 'needle' => $needle, 'handler' => $handler, 'used' => false];
    }

    /**
     * Registry 課題を 1 件用意する。
     *
     * @return array<string, mixed>
     */
    public function addRegistry(
        string $worldKey,
        string $achievementKey,
        bool $done,
        ?int $projectId = null,
        string $recordType = 'registry',
    ): array {
        $id = $this->nextId++;

        $issue = [
            'id' => $id,
            'projectId' => $projectId ?? $this->projectId,
            'issueKey' => $this->projectKey.'-'.$id,
            'summary' => '[Terraria Registry] '.$achievementKey,
            'status' => ['id' => $done ? $this->doneStatusId : $this->openStatusId, 'name' => $done ? '完了' : '未対応'],
            'customFields' => [
                ['id' => $this->fieldIds['record_type'], 'fieldTypeId' => 1, 'name' => 'Terraria Record Type', 'value' => $recordType],
                ['id' => $this->fieldIds['world_key'], 'fieldTypeId' => 1, 'name' => 'Terraria World Key', 'value' => $worldKey],
                ['id' => $this->fieldIds['achievement_key'], 'fieldTypeId' => 1, 'name' => 'Terraria Key', 'value' => $achievementKey],
            ],
        ];

        $this->issues[] = $issue;

        return $issue;
    }

    /**
     * Registry ではない課題 (攻略課題 / 研修課題) を 1 件用意する。
     */
    public function addPlainIssue(?string $worldKey = null, ?string $achievementKey = null, bool $done = false): void
    {
        $id = $this->nextId++;

        $customFields = [
            ['id' => $this->fieldIds['record_type'], 'fieldTypeId' => 1, 'name' => 'Terraria Record Type', 'value' => ''],
        ];

        if ($worldKey !== null) {
            $customFields[] = ['id' => $this->fieldIds['world_key'], 'fieldTypeId' => 1, 'name' => 'Terraria World Key', 'value' => $worldKey];
        }

        if ($achievementKey !== null) {
            $customFields[] = ['id' => $this->fieldIds['achievement_key'], 'fieldTypeId' => 1, 'name' => 'Terraria Key', 'value' => $achievementKey];
        }

        $this->issues[] = [
            'id' => $id,
            'projectId' => $this->projectId,
            'issueKey' => $this->projectKey.'-'.$id,
            'summary' => '研修課題',
            'status' => ['id' => $done ? $this->doneStatusId : $this->openStatusId, 'name' => $done ? '完了' : '未対応'],
            'customFields' => $customFields,
        ];
    }

    public function markDone(string $issueKey): void
    {
        foreach ($this->issues as $index => $issue) {
            if ($issue['issueKey'] === $issueKey) {
                $this->issues[$index]['status'] = ['id' => $this->doneStatusId, 'name' => '完了'];

                return;
            }
        }
    }

    /**
     * @return list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>}>
     */
    public function requestsMatching(string $method, string $needle): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === $method && str_contains($request['path'], $needle),
        ));
    }

    public function countRequests(string $method, string $needle): int
    {
        return count($this->requestsMatching($method, $needle));
    }

    public function writeCount(): int
    {
        return $this->countRequests('POST', '/api/v2/issues') + $this->countRequests('PATCH', '/api/v2/issues');
    }

    /**
     * Issue List (一覧取得) のリクエストだけを返す。単一 Issue の GET は含まない。
     *
     * @return list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>}>
     */
    public function listRequests(): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['method'] === 'GET' && $request['path'] === '/api/v2/issues',
        ));
    }

    /**
     * Achievement Key で絞り込んでいない全件 scan の回数。
     */
    public function fullScanCount(): int
    {
        $count = 0;

        foreach ($this->listRequests() as $request) {
            $narrowed = false;

            foreach (array_keys($request['query']) as $key) {
                if (str_starts_with((string) $key, 'customField_')) {
                    $narrowed = true;
                }
            }

            if (! $narrowed) {
                $count++;
            }
        }

        return $count;
    }

    private function handle(Request $request): PromiseInterface
    {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $method = strtoupper($request->method());
        $form = $request->isForm() ? $request->data() : [];

        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'form' => is_array($form) ? $form : [],
        ];

        foreach ($this->interceptors as $index => $interceptor) {
            if ($interceptor['used'] || $interceptor['method'] !== $method || ! str_contains($path, $interceptor['needle'])) {
                continue;
            }

            $this->interceptors[$index]['used'] = true;

            $result = ($interceptor['handler'])($this, $request);

            if ($result instanceof PromiseInterface) {
                return $result;
            }

            break;
        }

        if ($method === 'GET' && $path === '/api/v2/issues') {
            return $this->respondWithList($query);
        }

        if ($method === 'GET' && str_starts_with($path, '/api/v2/issues/')) {
            return $this->respondWithIssue(self::issueKeyFrom($path));
        }

        if ($method === 'POST' && $path === '/api/v2/issues') {
            return $this->createIssue(is_array($form) ? $form : []);
        }

        if ($method === 'PATCH' && str_starts_with($path, '/api/v2/issues/')) {
            return $this->updateIssue(self::issueKeyFrom($path), is_array($form) ? $form : []);
        }

        return Http::response(['errors' => [['message' => 'unexpected request: '.$method.' '.$path]]], 404);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function respondWithList(array $query): PromiseInterface
    {
        // 絞り込みを意図的に無視する。PHP 側の final match だけが頼りになる状況を作る。
        $count = isset($query['count']) ? (int) $query['count'] : 20;
        $offset = isset($query['offset']) ? (int) $query['offset'] : 0;

        return Http::response(array_slice($this->issues, $offset, $count), 200);
    }

    private function respondWithIssue(string $issueKey): PromiseInterface
    {
        foreach ($this->issues as $issue) {
            if ($issue['issueKey'] === $issueKey) {
                return Http::response($issue, 200);
            }
        }

        return Http::response(['errors' => [['message' => 'No such issue']]], 404);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function createIssue(array $form): PromiseInterface
    {
        $id = $this->nextId++;

        $customFields = [];

        foreach ($form as $key => $value) {
            if (! str_starts_with((string) $key, 'customField_')) {
                continue;
            }

            $customFields[] = [
                'id' => (int) substr((string) $key, strlen('customField_')),
                'fieldTypeId' => 1,
                'name' => 'custom',
                'value' => (string) $value,
            ];
        }

        $issue = [
            'id' => $id,
            'projectId' => (int) ($form['projectId'] ?? 0),
            'issueKey' => $this->projectKey.'-'.$id,
            'summary' => (string) ($form['summary'] ?? ''),
            'description' => (string) ($form['description'] ?? ''),
            'status' => ['id' => $this->openStatusId, 'name' => '未対応'],
            'customFields' => $customFields,
        ];

        $this->issues[] = $issue;

        return Http::response($issue, 201);
    }

    /**
     * @param  array<string, mixed>  $form
     */
    private function updateIssue(string $issueKey, array $form): PromiseInterface
    {
        foreach ($this->issues as $index => $issue) {
            if ($issue['issueKey'] !== $issueKey) {
                continue;
            }

            if (isset($form['statusId'])) {
                $statusId = (int) $form['statusId'];
                $this->issues[$index]['status'] = [
                    'id' => $statusId,
                    'name' => $statusId === $this->doneStatusId ? '完了' : '未対応',
                ];
            }

            return Http::response($this->issues[$index], 200);
        }

        return Http::response(['errors' => [['message' => 'No such issue']]], 404);
    }

    private static function issueKeyFrom(string $path): string
    {
        return rawurldecode(substr($path, strlen('/api/v2/issues/')));
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }
}
