<?php

declare(strict_types=1);

namespace App\Infrastructure\Lock;

/**
 * `world_key + achievement_key` 単位の cross-process lock (docs/design.md §10.5)。
 *
 * MVP は PHP Bridge を単一ホストで運用するが、PHP-FPM 等で複数 Worker が並走する。
 * in-memory mutex では Worker 間の重複作成を防げないため、同一ホストの全 Worker が
 * 共有する OS レベルの lock (flock) を必須とする。
 *
 * 複数ホストへの水平スケールは MVP 非対応 (distributed lock は導入しない)。
 */
interface CrossProcessLockFactory
{
    /**
     * lock を取得して返す。取得できなければ例外を投げる。
     *
     * ゲーム進行を長時間ブロックしないため、待機は有限時間で打ち切る
     * (docs/spec.md §9: Backlog 通信でゲームのメイン処理を待たせない)。
     *
     * @throws LockUnavailableException 制限時間内に取得できなかった場合
     */
    public function forAchievement(string $worldKey, string $achievementKey): CrossProcessLock;
}
