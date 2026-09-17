<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Backlog;

use JsonException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * API Key 非漏洩テストの土台となる RecordingLogger 自体の検証。
 *
 * dump() が encode 失敗を握りつぶして空文字列を返すと、
 * assertStringNotContainsString($apiKey, '') が常に成功してしまう。
 */
final class RecordingLoggerTest extends TestCase
{
    #[Test]
    public function it_throws_instead_of_returning_an_empty_string_when_encoding_fails(): void
    {
        $logger = new RecordingLogger;

        // 不正な UTF-8 バイト列は json_encode が false を返す。
        $logger->info("invalid \xB1\x31 utf-8");

        $this->expectException(JsonException::class);

        $logger->dump();
    }

    #[Test]
    public function it_dumps_recorded_entries_as_json(): void
    {
        $logger = new RecordingLogger;

        $logger->warning('請求上限', ['status' => 429]);

        $dump = $logger->dump();

        $this->assertStringContainsString('請求上限', $dump);
        $this->assertStringContainsString('429', $dump);
    }
}
