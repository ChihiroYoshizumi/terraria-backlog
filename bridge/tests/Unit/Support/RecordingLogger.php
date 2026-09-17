<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * 診断ログ (`item.invalid_skipped`) を検証するためのテスト用 logger。
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: string, message: string, context: array<string, mixed>}>
     */
    public function withMessage(string $message): array
    {
        return array_values(array_filter(
            $this->records,
            static fn (array $record): bool => $record['message'] === $message,
        ));
    }
}
