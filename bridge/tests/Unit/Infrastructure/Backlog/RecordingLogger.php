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
     *
     * encode 失敗を握りつぶすと空文字列が返り、API Key 非漏洩の
     * assertStringNotContainsString() が常に成功する偽陽性になる。
     * JSON_THROW_ON_ERROR で必ず失敗を表面化させる。
     *
     * @throws \JsonException
     */
    public function dump(): string
    {
        return json_encode(
            $this->records,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }
}
