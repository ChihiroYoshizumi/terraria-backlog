<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Lock;

use App\Infrastructure\Lock\FileLockFactory;
use App\Infrastructure\Lock\LockUnavailableException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/design.md §10.5 (競合制御) の検証。
 *
 * 「同一ホストの全 PHP Worker で共有する OS file lock」であることが要件なので、
 * 単一プロセス内の擬似テストでは足りない。`proc_open()` で実際に別プロセスを
 * 起動し、critical section が重ならないことを時刻で確認する。
 */
final class FileLockTest extends TestCase
{
    private const WORLD_A = 'terraria:111111111';

    private const WORLD_B = 'terraria:222222222';

    private string $lockDir;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lockDir = sys_get_temp_dir().'/terraria-registry-lock-test-'.bin2hex(random_bytes(6));
        $this->logFile = $this->lockDir.'-log.txt';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->lockDir)) {
            foreach ((array) glob($this->lockDir.'/*') as $file) {
                if (is_string($file)) {
                    @unlink($file);
                }
            }

            @rmdir($this->lockDir);
        }

        @unlink($this->logFile);

        parent::tearDown();
    }

    /**
     * 別プロセスを起動する。
     *
     * @return resource
     */
    private function spawn(string $worldKey, string $achievementKey, int $holdMicroseconds, string $label)
    {
        $command = [
            PHP_BINARY,
            __DIR__.'/lock_worker.php',
            $this->lockDir,
            $worldKey,
            $achievementKey,
            $this->logFile,
            (string) $holdMicroseconds,
            $label,
        ];

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        $this->assertIsResource($process, '別プロセスを起動できなかった。');

        // 親側でパイプを溜めないよう non-blocking にしておく。
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        return $process;
    }

    /**
     * @param  list<resource>  $processes
     */
    private function waitAll(array $processes): void
    {
        foreach ($processes as $process) {
            while (true) {
                $status = proc_get_status($process);

                if (! $status['running']) {
                    $this->assertSame(0, $status['exitcode'], 'worker プロセスが異常終了した。');

                    break;
                }

                usleep(5_000);
            }

            proc_close($process);
        }
    }

    /**
     * @return list<array{event: string, label: string, at: float}>
     */
    private function readLog(): array
    {
        $contents = (string) file_get_contents($this->logFile);
        $events = [];

        foreach (explode("\n", trim($contents)) as $line) {
            if ($line === '') {
                continue;
            }

            [$event, $label, $at] = explode(' ', $line);
            $events[] = ['event' => $event, 'label' => $label, 'at' => (float) $at];
        }

        usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $events;
    }

    #[Test]
    public function concurrent_processes_are_serialized_by_the_shared_file_lock(): void
    {
        touch($this->logFile);

        // 同じ world_key + achievement_key を 3 プロセスで奪い合う。
        $processes = [];

        foreach (['a', 'b', 'c'] as $label) {
            $processes[] = $this->spawn(self::WORLD_A, 'item:1326', 200_000, $label);
        }

        $this->waitAll($processes);

        $events = $this->readLog();

        $this->assertCount(6, $events, '各プロセスが enter / leave を記録していない。');

        // critical section が入れ子・重複していないこと。
        $inside = 0;

        foreach ($events as $event) {
            if ($event['event'] === 'enter') {
                $inside++;
                $this->assertSame(1, $inside, sprintf(
                    'lock 内に同時に %d プロセスが入った (直列化されていない)。',
                    $inside,
                ));

                continue;
            }

            $inside--;
        }

        $this->assertSame(0, $inside);

        // enter -> leave -> enter -> leave ... の順で並ぶ。
        $this->assertSame(
            ['enter', 'leave', 'enter', 'leave', 'enter', 'leave'],
            array_column($events, 'event'),
        );
    }

    #[Test]
    public function different_achievements_do_not_block_each_other(): void
    {
        touch($this->logFile);

        // lock は world_key + achievement_key 単位。別 Achievement / 別 World は
        // 互いをブロックしない (直列化の粒度が Snapshot 全体にならないこと)。
        $processes = [
            $this->spawn(self::WORLD_A, 'item:1326', 600_000, 'a'),
            $this->spawn(self::WORLD_A, 'boss:plantera', 600_000, 'b'),
            $this->spawn(self::WORLD_B, 'item:1326', 600_000, 'c'),
        ];

        $this->waitAll($processes);

        $events = $this->readLog();
        $maxInside = 0;
        $inside = 0;

        foreach ($events as $event) {
            $inside += $event['event'] === 'enter' ? 1 : -1;
            $maxInside = max($maxInside, $inside);
        }

        $this->assertGreaterThan(
            1,
            $maxInside,
            '別 world_key / achievement_key が不必要に直列化されている。',
        );
    }

    #[Test]
    public function the_same_world_and_achievement_map_to_the_same_lock_file(): void
    {
        $factory = new FileLockFactory($this->lockDir);

        $this->assertSame(
            FileLockFactory::lockName(self::WORLD_A, 'item:1326'),
            FileLockFactory::lockName(self::WORLD_A, 'item:1326'),
        );

        $this->assertNotSame(
            FileLockFactory::lockName(self::WORLD_A, 'item:1326'),
            FileLockFactory::lockName(self::WORLD_B, 'item:1326'),
        );

        // 連結の境界を hash に含めており、("a"+"bc") と ("ab"+"c") が衝突しない。
        $this->assertNotSame(
            FileLockFactory::lockName('a', 'bc'),
            FileLockFactory::lockName('ab', 'c'),
        );

        $lock = $factory->forAchievement(self::WORLD_A, 'item:1326');

        $this->assertFileExists($factory->pathFor(FileLockFactory::lockName(self::WORLD_A, 'item:1326')));

        $lock->release();
    }

    #[Test]
    public function it_gives_up_instead_of_waiting_forever(): void
    {
        touch($this->logFile);

        // 別プロセスに長く握らせたまま、短い timeout の factory で取りに行く。
        $process = $this->spawn(self::WORLD_A, 'item:1326', 1_500_000, 'holder');

        // worker が lock を取るまで待つ。
        $deadline = microtime(true) + 5.0;

        while (trim((string) file_get_contents($this->logFile)) === '' && microtime(true) < $deadline) {
            usleep(5_000);
        }

        $factory = new FileLockFactory($this->lockDir, 0.2, 10_000);

        try {
            $factory->forAchievement(self::WORLD_A, 'item:1326');
            $this->fail('lock を取得できてしまった。');
        } catch (LockUnavailableException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->waitAll([$process]);
        }
    }
}
