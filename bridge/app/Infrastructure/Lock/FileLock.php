<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

/**
 * `flock(LOCK_EX)` による OS file lock (docs/design.md §10.5)。
 *
 * - lock file の内容は正本ではなく、消えてもよい。
 * - プロセスが異常終了しても OS が file descriptor を閉じた時点で解放される。
 * - lock file は削除しない。解放と同時に unlink すると、待機中の別プロセスが
 *   既に unlink 済みの inode を掴んだままになり排他が壊れる。
 */
final class FileLock implements CrossProcessLock
{
    /** @var resource|null */
    private $handle;

    /**
     * @param  resource  $handle  flock(LOCK_EX) 取得済みの file handle
     */
    public function __construct(
        $handle,
        private readonly string $name,
        private readonly string $path,
    ) {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        $handle = $this->handle;
        $this->handle = null;

        @flock($handle, LOCK_UN);
        @fclose($handle);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * release() を呼び忘れても、プロセス終了時までに解放されるようにする。
     */
    public function __destruct()
    {
        $this->release();
    }
}
