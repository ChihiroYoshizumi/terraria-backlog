<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Infrastructure\Backlog\Exceptions\BacklogApiException;

/**
 * Backlog Issue List の全ページ取得 (docs/design.md §13.2, §20)。
 *
 * ```text
 * offset = 0
 * count  = 100
 * loop:
 *     issues = GET /api/v2/issues(projectId[]=..., offset, count)
 *     collect issues
 *     if issues.count < count: break
 *     offset += count
 * ```
 *
 * 重要:
 * - 1ページだけを全件とみなさない。取りこぼしは Registry の重複登録に直結する。
 * - 検索障害を「該当0件」と扱わない。API 例外はそのまま呼び出し元へ伝播させる。
 * - query は必ず対象 Project ID へ限定する。Adapter payload の Project を信用しない
 *   (docs/design.md §16.3)。
 *
 * Task 05 (Registry) / Task 06 (Mapping) の scan はいずれも本 helper を利用する。
 */
final class IssueListPaginator
{
    /** Backlog API の count 上限。 */
    public const MAX_COUNT = 100;

    public function __construct(
        private readonly BacklogClient $client,
        private readonly int $pageSize = self::MAX_COUNT,
        private readonly int $maxPages = 200,
    ) {
        if ($this->pageSize < 1 || $this->pageSize > self::MAX_COUNT) {
            throw new BacklogApiException('Backlog Issue List の count は 1..100 の範囲である必要がある.');
        }
    }

    /**
     * 対象 Project の Issue を全ページ取得する。
     *
     * @param  array<string, mixed>  $filters  projectId / count / offset 以外の絞り込み
     * @return list<array<string, mixed>>
     */
    public function fetchAll(int $projectId, array $filters = []): array
    {
        foreach (['projectId', 'count', 'offset'] as $reserved) {
            if (array_key_exists($reserved, $filters)) {
                throw new BacklogApiException(
                    sprintf('Issue List の "%s" は paginator が管理するため filters で指定できない.', $reserved),
                );
            }
        }

        $issues = [];
        $offset = 0;

        for ($page = 0; $page < $this->maxPages; $page++) {
            $query = array_merge($filters, [
                'projectId' => [$projectId],
                'count' => $this->pageSize,
                'offset' => $offset,
            ]);

            $batch = $this->client->get('/api/v2/issues', $query)->list();

            foreach ($batch as $issue) {
                $issues[] = $issue;
            }

            if (count($batch) < $this->pageSize) {
                return $issues;
            }

            $offset += $this->pageSize;
        }

        // 打ち切りを「全件取得できた」と扱うと取りこぼしになるため fail closed。
        throw new BacklogApiException(
            sprintf('Backlog Issue List の取得が上限 %d ページを超えた. 全件取得を保証できない.', $this->maxPages),
            null,
            'backlog.pagination_limit_exceeded',
        );
    }
}
