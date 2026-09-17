<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use Illuminate\Http\Client\Response;

/**
 * Backlog の `X-RateLimit-*` Header (docs/design.md §13.3)。
 *
 * https://developer.nulab.com/docs/backlog/rate-limit/
 * - X-RateLimit-Limit     : 1分あたりの上限
 * - X-RateLimit-Remaining : 現在の window の残り
 * - X-RateLimit-Reset     : window がリセットされる UTC epoch 秒
 *
 * 診断ログ用の metadata としてのみ利用し、制御フローの判断には使わない。
 */
final readonly class RateLimitStatus
{
    public function __construct(
        public ?int $limit = null,
        public ?int $remaining = null,
        public ?int $reset = null,
    ) {}

    public static function fromResponse(Response $response): self
    {
        return new self(
            self::intHeader($response, 'X-RateLimit-Limit'),
            self::intHeader($response, 'X-RateLimit-Remaining'),
            self::intHeader($response, 'X-RateLimit-Reset'),
        );
    }

    /**
     * @return array<string, int|null>
     */
    public function toLogContext(): array
    {
        return [
            'rate_limit' => $this->limit,
            'rate_limit_remaining' => $this->remaining,
            'rate_limit_reset' => $this->reset,
        ];
    }

    private static function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
