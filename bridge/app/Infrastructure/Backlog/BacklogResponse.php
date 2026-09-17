<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Infrastructure\Backlog\Exceptions\BacklogApiException;

/**
 * Backlog API の成功レスポンス。
 *
 * 失敗は例外として表現するため、本オブジェクトが存在することは
 * 「Backlog から有効な応答を得た」ことを意味する (docs/design.md §13.4)。
 */
final readonly class BacklogResponse
{
    /**
     * @param  array<array-key, mixed>  $json
     */
    public function __construct(
        public int $status,
        public array $json,
        public RateLimitStatus $rateLimit,
    ) {}

    /**
     * 配列を返す endpoint 用。
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        if (! array_is_list($this->json)) {
            throw new BacklogApiException(
                'Backlog API が配列以外を返した (expected JSON array).',
                $this->status,
                'backlog.unexpected_payload',
            );
        }

        $items = [];

        foreach ($this->json as $item) {
            if (! is_array($item)) {
                throw new BacklogApiException(
                    'Backlog API の配列要素が object ではない.',
                    $this->status,
                    'backlog.unexpected_payload',
                );
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * object を返す endpoint 用。
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        if (array_is_list($this->json) && $this->json !== []) {
            throw new BacklogApiException(
                'Backlog API が object 以外を返した (expected JSON object).',
                $this->status,
                'backlog.unexpected_payload',
            );
        }

        /** @var array<string, mixed> */
        return $this->json;
    }
}
