<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Snapshot\SnapshotNotification;
use App\Domain\Snapshot\SnapshotReason;
use App\Domain\Snapshot\WorldSnapshot;

/**
 * Adapter が解釈不要な「完成済み通知命令」を組み立てる (docs/design.md §15)。
 *
 * ここには意味の異なる **2 つの通知経路** があり、互いに混ざらないよう
 * 別メソッドに分けている。呼び出し側 ({@see ProcessWorldSnapshot}) も
 * この 2 つを個別に呼ぶ。
 *
 * | 経路 | 契機 | 宛先 | 条件 |
 * | --- | --- | --- | --- |
 * | {@see immediatePlayerAcks()} | `collection_change` のみ | 今回の `trigger.playerNames` | 対象 Item の Registry 保存確認 |
 * | {@see recoveryNotifications()} | `periodic` / `manual` のみ | server console | 復旧待ち + 再照合が全件成功 |
 *
 * 分離の要点 (docs/design.md §14.3, §15.2):
 *
 * - recovery notification は Item 名・Player 名・「取り出してOK」を含めない。
 * - 過去の `trigger.playerNames` を保存・復元しない。この class は
 *   引数で渡された「今回の Snapshot」以外の Player 情報へ一切アクセスできない。
 * - immediate ACK は Mapping 完了の成否を条件にしない。
 */
final readonly class BuildNotifications
{
    /**
     * Collection Chest の即時 ACK 文言 (docs/design.md §15, §6.5)。
     */
    private const string ACK_REGISTERED = '[Backlog] %s を登録しました。取り出してOKです。';

    /**
     * 既に登録済みだったことを確認できた場合。保存済みである事実は同じなので
     * ACK は出すが、「登録しました」とは言わない (docs/spec.md §9 の
     * 「ACK が届かない -> 再照合で既存登録を確認し ACK を再表示可能」)。
     */
    private const string ACK_ALREADY_REGISTERED = '[Backlog] %s は登録済みです。取り出してOKです。';

    /**
     * docs/design.md §15.2 の固定文言。Item 名も Player 名も含めない。
     */
    public const string RECOVERY_MESSAGE = '[Backlog] 復旧後の再同期が完了しました。';

    /**
     * 今回の操作 Player への item-specific ACK。
     *
     * `collection_change` 以外では常に空を返す。Item 単位通知は
     * `collection_change` に限る (docs/design.md §15.3)。
     *
     * 部分失敗では成功した Item だけが `$confirmed` に入っている前提であり、
     * 失敗 Item をここで再構成することはできない。
     *
     * @param  list<ConfirmedItemRegistration>  $confirmed  Registry 保存を確認できた Item だけ
     * @return list<SnapshotNotification>
     */
    public function immediatePlayerAcks(WorldSnapshot $snapshot, array $confirmed): array
    {
        if ($snapshot->reason !== SnapshotReason::CollectionChange) {
            return [];
        }

        $playerNames = $this->recipients($snapshot);

        // 宛先が無ければ通知そのものを作らない。全体チャットへ落とさない。
        if ($playerNames === [] || $confirmed === []) {
            return [];
        }

        $notifications = [];

        foreach ($confirmed as $registration) {
            $notifications[] = SnapshotNotification::toPlayers($playerNames, sprintf(
                $registration->written ? self::ACK_REGISTERED : self::ACK_ALREADY_REGISTERED,
                $registration->itemName,
            ));
        }

        return $notifications;
    }

    /**
     * 障害復旧後の server console 向け一般通知 (docs/design.md §14.3, §15.2)。
     *
     * 生成条件は 3 つすべて。
     *
     * 1. `reason` が `periodic` / `manual` である。
     * 2. Adapter がワールド単位の復旧待ちフラグを立てている (`recoveryPending`)。
     * 3. 本 Snapshot の再照合が失敗なく完了した。
     *
     * 過去 Player への ACK は復元しない。宛先は常に server console 1 件だけで、
     * `playerNames` は付かない。
     *
     * @return list<SnapshotNotification>
     */
    public function recoveryNotifications(WorldSnapshot $snapshot, bool $reconciled): array
    {
        if (! $this->isReconciliationSweep($snapshot->reason)) {
            return [];
        }

        if (! $snapshot->recoveryPending || ! $reconciled) {
            return [];
        }

        return [SnapshotNotification::toServerConsole(self::RECOVERY_MESSAGE)];
    }

    /**
     * 同一 debounce window の複数 Player を重複排除する (docs/design.md §15.2)。
     *
     * @return list<string>
     */
    private function recipients(WorldSnapshot $snapshot): array
    {
        $unique = [];

        foreach ($snapshot->triggerPlayerNames as $name) {
            if ($name === '') {
                continue;
            }

            $unique[$name] = true;
        }

        return array_map(strval(...), array_keys($unique));
    }

    private function isReconciliationSweep(SnapshotReason $reason): bool
    {
        return $reason === SnapshotReason::Periodic || $reason === SnapshotReason::Manual;
    }
}
