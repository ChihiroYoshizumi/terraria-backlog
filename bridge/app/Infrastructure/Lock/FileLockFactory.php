<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

/**
 * `world_key + achievement_key` 単位の cross-process file lock (docs/design.md §10.5)。
 *
 * ```text
 * <lock directory>/<sha256(world_key + "\0" + achievement_key)>.lock
 * ```
 *
 * `flock(LOCK_EX)` を使う。同一ホスト上の全 PHP Worker が同じ lock directory を
 * 共有するため、PHP-FPM の別プロセス間でも直列化される。in-memory mutex では
 * この保証が得られないため採用しない。
 *
 * 待機は `LOCK_NB` + sleep のループで行い、上限時間で打ち切る。無期限に blocking
 * すると Backlog 遅延時にゲーム側の処理を待たせてしまう (docs/spec.md §9)。
 */
final class FileLockFactory implements CrossProcessLockFactory
{
    public function __construct(
        private readonly string $directory,
        private readonly float $timeoutSeconds = 10.0,
        private readonly int $retryIntervalMicroseconds = 20_000,
    ) {}

    public function forAchievement(string $worldKey, string $achievementKey): CrossProcessLock
    {
        return $this->acquire(self::lockName($worldKey, $achievementKey));
    }

    /**
     * lock file 名。world_key / achievement_key をそのまま file 名にすると
     * 長さ・使用可能文字の制約を受けるため hash 化する。区切りに NUL を挟み、
     * ("a"+"bc") と ("ab"+"c") が同じ hash にならないようにする。
     */
    public static function lockName(string $worldKey, string $achievementKey): string
    {
        return hash('sha256', $worldKey."\0".$achievementKey);
    }

    public function acquire(string $name): CrossProcessLock
    {
        $path = $this->pathFor($name);

        $this->ensureDirectory();

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new LockUnavailableException(
                sprintf('lock file を開けなかった: %s', $path),
            );
        }

        $deadline = microtime(true) + $this->timeoutSeconds;

        do {
            if (@flock($handle, LOCK_EX | LOCK_NB)) {
                return new FileLock($handle, $name, $path);
            }

            usleep($this->retryIntervalMicroseconds);
        } while (microtime(true) < $deadline);

        @fclose($handle);

        throw new LockUnavailableException(
            sprintf('lock を %.1f 秒以内に取得できなかった (%s).', $this->timeoutSeconds, $name),
        );
    }

    public function pathFor(string $name): string
    {
        return rtrim($this->directory, '/').'/'.$name.'.lock';
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            return;
        }

        // 並行して別 Worker が作成した場合の race は is_dir() の再確認で吸収する。
        if (! @mkdir($this->directory, 0o775, true) && ! is_dir($this->directory)) {
            throw new LockUnavailableException(
                sprintf('lock directory を作成できなかった: %s', $this->directory),
            );
        }
    }
}
