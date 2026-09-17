<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog\Mapping;

use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Mapping Repository のテスト用 Backlog stub (Task 06)。
 *
 * 実 Backlog へは接続しない。意図的に「意地悪な」API を演じる。
 *
 * - Issue List は query の絞り込み (`projectId` / `customField_*` / `statusId`) を
 *   **無視して全件返す**。Backlog の文字列検索を exact lookup とみなさない実装
 *   (docs/design.md §11, §20) を検証するため、PHP 側の final match だけが
 *   絞り込みになる状況を作る。
 * - `interceptNext()` で timeout / エラー応答を 1 回だけ差し込める。
 * - scan 後・PATCH 前に利用者が課題を編集した状況 (reopen / Mapping 解除 /
 *   手動完了) を `mutate*()` で作れる。
 * - 送信されたリクエストは自前で記録する。例外を投げるケースでは Laravel 側の
 *   recorded に残らないため。
 *
 * Task 05 の `FakeBacklog` とは意図的に分離している (別 Task が並行編集中のため)。
 */
final class FakeMappingBacklog
{
    /** @var list<array<string, mixed>> */
    public array $issues = [];

    /** @var list<array{method: string, path: string, query: array<string, mixed>, form: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{method: string, needle: string, handler: Closure, used: bool}> */
    private array $interceptors = [];

    private int $nextId = 2000;

    /**
     * @param  array{record_type: int, world_key: int, achievement_key: int}  $fieldIds
     */
    public function __construct(
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

    public function interceptNext(string $method, string $needle, Closure $handler): void
    {
        $this->interceptors[] = ['method' => $method, 'needle' => $needle, 'handler' => $handler, 'used' => false];
    }

    /**
     * 有効な Mapping を持つ攻略課題。
     */
    public function addMappingIssue(
        string $worldKey,
        string $achievementKey,
        bool $done = false,
        ?int $projectId = null,
        string $recordType = '',
        string $summary = '攻略課題',
    ): string {
        return $this->addIssue(
            summary: $summary,
            done: $done,
            projectId: $projectId,
            customFields: [
                $this->fieldIds['record_type'] => $recordType,
                $this->fieldIds['world_key'] => $worldKey,
                $this->fieldIds['achievement_key'] => $achievementKey,
            ],
        );
    }

    /**
     * Registry 課題 (`Terraria Record Type = registry`)。Mapping からは除外される。
     */
    public function addRegistryIssue(string $worldKey, string $achievementKey, bool $done = true): string
    {
        return $this->addMappingIssue(
            worldKey: $worldKey,
            achievementKey: $achievementKey,
            done: $done,
            recordType: 'registry',
            summary: '[Terraria Registry] '.$achievementKey,
        );
    }

    /**
     * Mapping 属性が無い一般 / 研修課題。Custom Field 自体が応答に現れない。
     */
    public function addTrainingIssue(bool $done = false, string $summary = '研修課題'): string
    {
        return $this->addIssue(summary: $summary, done: $done, customFields: []);
    }

    /**
     * @param  array<int, string>  $customFields  field id => value
     */
    public function addIssue(
        string $summary,
        bool $done = false,
        ?int $projectId = null,
        array $customFields = [],
        ?int $statusId = null,
        bool $withStatus = true,
    ): string {
        $id = $this->nextId++;
        $issueKey = $this->projectKey.'-'.$id;

        $issue = [
            'id' => $id,
            'projectId' => $projectId ?? $this->projectId,
            'issueKey' => $issueKey,
            'summary' => $summary,
            'customFields' => $this->customFieldPayload($customFields),
        ];

        if ($withStatus) {
            $issue['status'] = $this->statusPayload($statusId ?? ($done ? $this->doneStatusId : $this->openStatusId));
        }

        $this->issues[] = $issue;

        return $issueKey;
    }

    /**
     * 利用者が課題を完了した / reopen した状況を作る。
     */
    public function setStatus(string $issueKey, bool $done): void
    {
        $this->mutate($issueKey, function (array $issue) use ($done): array {
            $issue['status'] = $this->statusPayload($done ? $this->doneStatusId : $this->openStatusId);

            return $issue;
        });
    }

    /**
     * 利用者が Custom Field を編集した状況を作る。null で属性そのものを削除する。
     */
    public function setCustomField(string $issueKey, int $fieldId, ?string $value): void
    {
        $this->mutate($issueKey, static function (array $issue) use ($fieldId, $value): array {
            /** @var list<array<string, mixed>> $fields */
            $fields = $issue['customFields'];
            $remaining = array_values(array_filter(
                $fields,
                static fn (array $field): bool => $field['id'] !== $fieldId,
            ));

            if ($value !== null) {
                $remaining[] = ['id' => $fieldId, 'fieldTypeId' => 1, 'name' => 'custom', 'value' => $value];
            }

            $issue['customFields'] = $remaining;

            return $issue;
        });
    }

    public function setProjectId(string $issueKey, int $projectId): void
    {
        $this->mutate($issueKey, static function (array $issue) use ($projectId): array {
            $issue['projectId'] = $projectId;

            return $issue;
        });
    }

    public function isDone(string $issueKey): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue['issueKey'] === $issueKey) {
                /** @var array{id: int} $status */
                $status = $issue['status'] ?? ['id' => 0];

                return $status['id'] === $this->doneStatusId;
            }
        }

        return false;
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

    /**
     * 状態を変えうるリクエスト。研修課題を触っていないことの検証に使う。
     */
    public function writeCount(): int
    {
        return $this->countRequests('POST', '/api/v2/issues')
            + $this->countRequests('PATCH', '/api/v2/issues')
            + $this->countRequests('PUT', '/api/v2/issues')
            + $this->countRequests('DELETE', '/api/v2/issues');
    }

    /**
     * PATCH された Issue Key の一覧 (送信順)。
     *
     * @return list<string>
     */
    public function patchedIssueKeys(): array
    {
        return array_values(array_map(
            static fn (array $request): string => rawurldecode(substr($request['path'], strlen('/api/v2/issues/'))),
            $this->requestsMatching('PATCH', '/api/v2/issues/'),
        ));
    }

    /**
     * Issue List (一覧取得) のリクエスト。単一 Issue の GET は含まない。
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
     * Achievement Key 等で絞り込んでいない full scan の回数。
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
    private function updateIssue(string $issueKey, array $form): PromiseInterface
    {
        foreach ($this->issues as $index => $issue) {
            if ($issue['issueKey'] !== $issueKey) {
                continue;
            }

            if (isset($form['statusId'])) {
                $this->issues[$index]['status'] = $this->statusPayload((int) $form['statusId']);
            }

            return Http::response($this->issues[$index], 200);
        }

        return Http::response(['errors' => [['message' => 'No such issue']]], 404);
    }

    /**
     * @param  Closure(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutate(string $issueKey, Closure $mutator): void
    {
        foreach ($this->issues as $index => $issue) {
            if ($issue['issueKey'] === $issueKey) {
                $this->issues[$index] = $mutator($issue);

                return;
            }
        }
    }

    /**
     * @param  array<int, string>  $customFields
     * @return list<array<string, mixed>>
     */
    private function customFieldPayload(array $customFields): array
    {
        $payload = [];

        foreach ($customFields as $id => $value) {
            $payload[] = ['id' => $id, 'fieldTypeId' => 1, 'name' => 'custom', 'value' => $value];
        }

        return $payload;
    }

    /**
     * @return array{id: int, name: string}
     */
    private function statusPayload(int $statusId): array
    {
        return ['id' => $statusId, 'name' => $statusId === $this->doneStatusId ? '完了' : '未対応'];
    }

    private static function issueKeyFrom(string $path): string
    {
        return rawurldecode(substr($path, strlen('/api/v2/issues/')));
    }
}
