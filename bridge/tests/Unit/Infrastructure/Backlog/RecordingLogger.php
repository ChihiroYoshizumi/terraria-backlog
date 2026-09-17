<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use Psr\Log\AbstractLogger;

/**
 * BacklogClient が出力したログを丸ごと保持して検証するためのテスト用 Logger。
 *
 * AC-16 / docs/design.md §16.2: ログに API Key が現れないことを検証する。
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * 出力された全内容を1つの文字列として返す。
     */
    public function dump(): string
    {
        return (string) json_encode($this->records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
