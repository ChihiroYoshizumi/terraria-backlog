<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Support;

/**
 * Backlog API Key を文字列から取り除く (docs/spec.md §10, docs/design.md §16.2)。
 *
 * API Key は例外メッセージ・HTTP debug log・`terraria:doctor` の出力を含む
 * あらゆる出力へ出さない。生成箇所で出さないことを原則とし、本クラスは
 * その原則が破れた場合の最後の防波堤として使う。
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[REDACTED]';

    /**
     * @param  list<string|null>  $secrets
     */
    public function __construct(private readonly array $secrets = []) {}

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if (! is_string($secret) || $secret === '') {
                continue;
            }

            $text = str_replace($secret, self::PLACEHOLDER, $text);
            $text = str_replace(rawurlencode($secret), self::PLACEHOLDER, $text);
        }

        return $text;
    }
}
