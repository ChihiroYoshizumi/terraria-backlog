<?php

declare(strict_types=1);

namespace App\Domain\Snapshot;

use InvalidArgumentException;

/**
 * Adapter がそのまま表示する通知命令 (docs/design.md §6.5, §15)。
 *
 * contracts/snapshot-response-v1.schema.json と 1:1 で対応する。
 * Achievement Key / Backlog Issue Key / Registry 結果 / Mapping 結果は含めない。
 */
final readonly class SnapshotNotification
{
    /**
     * @param  list<string>  $playerNames
     */
    private function __construct(
        public NotificationAudience $audience,
        public string $message,
        public array $playerNames,
    ) {}

    /**
     * @param  list<string>  $playerNames
     */
    public static function toPlayers(array $playerNames, string $message): self
    {
        if ($playerNames === []) {
            throw new InvalidArgumentException('audience=players requires at least one player name.');
        }

        return new self(NotificationAudience::Players, self::assertMessage($message), array_values($playerNames));
    }

    public static function toServerConsole(string $message): self
    {
        return new self(NotificationAudience::Server, self::assertMessage($message), []);
    }

    /**
     * @return array{audience: string, message: string, playerNames?: list<string>}
     */
    public function toArray(): array
    {
        $payload = ['audience' => $this->audience->value];

        // schema: audience=server では playerNames を含めない。
        if ($this->audience === NotificationAudience::Players) {
            $payload['playerNames'] = $this->playerNames;
        }

        $payload['message'] = $this->message;

        return $payload;
    }

    private static function assertMessage(string $message): string
    {
        if ($message === '') {
            throw new InvalidArgumentException('notification message must not be empty.');
        }

        return $message;
    }
}
