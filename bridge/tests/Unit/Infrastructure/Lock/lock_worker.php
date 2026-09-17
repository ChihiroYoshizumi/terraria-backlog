<?php

declare(strict_types=1);

/*
 * `FileLockTest` が proc_open() で起動する別プロセスの worker。
 *
 * 単一プロセス内の擬似テストでは cross-process 排他を証明できないため、
 * 本当に別 OS プロセスから同じ lock file を奪い合わせる。
 *
 * usage: php lock_worker.php <lockDir> <worldKey> <achievementKey> <logFile> <holdMicroseconds> <label>
 */

require __DIR__.'/../../../../vendor/autoload.php';

use App\Infrastructure\Lock\FileLockFactory;

[, $lockDir, $worldKey, $achievementKey, $logFile, $holdMicroseconds, $label] = $argv;

$factory = new FileLockFactory($lockDir, 30.0, 2_000);

$lock = $factory->forAchievement($worldKey, $achievementKey);

$append = static function (string $line) use ($logFile): void {
    $handle = fopen($logFile, 'a');

    if ($handle === false) {
        exit(2);
    }

    // log file 自体も複数プロセスで共有するため、追記を flock で保護する。
    flock($handle, LOCK_EX);
    fwrite($handle, $line."\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
};

$append(sprintf('enter %s %.6f', $label, microtime(true)));

usleep((int) $holdMicroseconds);

$append(sprintf('leave %s %.6f', $label, microtime(true)));

$lock->release();

exit(0);
