<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Achievement\Achievement;
use App\Domain\Achievement\AchievementPrefix;
use App\Domain\Registry\RegistryResult;

/**
 * 「この request 内で Registry 保存を確認できた Item Achievement」1 件
 * (docs/design.md §15)。
 *
 * この型の存在自体が immediate player ACK の前提条件を表す。
 * {@see fromRegistryResult()} でしか作れず、`RegistryResult::isPersisted()` が
 * false のものは null になるため、保存未確認・duplicate incomplete の
 * fail closed は ACK 経路へ入り込めない。
 *
 * Mapping の完了成否はここに含めない。ACK は Registry 保存確認だけを意味する
 * (docs/design.md §15, AC-03)。
 */
final readonly class ConfirmedItemRegistration
{
    private function __construct(
        /** ACK 文言に使う表示名。catalog 由来の Item 名 (秘密情報ではない)。 */
        public string $itemName,
        /** 本 Snapshot で実際に書き込んだか。文言の出し分けにだけ使う。 */
        public bool $written,
    ) {}

    /**
     * Item Achievement かつ保存確認済みのときだけ生成する。
     *
     * Boss / World Achievement は Collection Chest の ACK 対象ではないため
     * null を返す (docs/design.md §15)。
     */
    public static function fromRegistryResult(Achievement $achievement, RegistryResult $result): ?self
    {
        if ($achievement->key->prefix !== AchievementPrefix::Item) {
            return null;
        }

        // 保存を確認できていない Achievement は成功 ACK の対象にしない
        // (docs/spec.md §9, AC-09 / AC-12)。
        if (! $result->isPersisted()) {
            return null;
        }

        return new self(self::displayName($achievement), $result->wasWritten());
    }

    /**
     * catalog 由来の Item 名。取れない場合でも Achievement Key を
     * ゲーム内へ出さず、Item type だけの中立な表示へ落とす。
     */
    private static function displayName(Achievement $achievement): string
    {
        $name = $achievement->metadata['itemName'] ?? null;

        if (is_string($name) && trim($name) !== '') {
            return $name;
        }

        $type = $achievement->metadata['itemType'] ?? null;

        return is_int($type) ? 'Item #'.$type : 'Item';
    }
}
