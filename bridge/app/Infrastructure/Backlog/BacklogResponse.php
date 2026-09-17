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
     * 空配列 [] も reject する。PHP は JSON の空 object {} を連想配列へ
     * デコードすると [] になり、空配列と区別できない。本システムが
     * object() を使う endpoint (Project 取得等) で {} が正常系として
     * 返ることは無いため、両者をまとめて異常として扱い fail closed に
     * 倒す (docs/design.md §13.4: 未確定を成功扱いしない)。
     * [] を通すと後続の $json['id'] 等が未定義となり、API 障害や想定外
     * レスポンスを成功と誤認する。
     *
     * @return array<string, mixed>
     */
    public function object(): array
    {
        if (array_is_list($this->json)) {
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
