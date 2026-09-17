#nullable disable
using System;
using System.Collections.Generic;
using Terraria;
using TerrariaBacklog.Adapter.Configuration;
using TerrariaBacklog.Adapter.Runtime;

namespace TerrariaBacklog.Adapter.Snapshots
{
    /// <summary>
    /// Terraria の現在状態を読み取って <see cref="WorldObservation"/> へコピーする薄い層
    /// (docs/design.md §7.2, §7.6)。
    ///
    /// **必ず game thread から呼ぶこと。** ここで読むのは
    /// <c>Terraria.Main</c> / <c>Terraria.NPC</c> / <c>Terraria.Main.chest</c> の
    /// 現在値だけで、event 引数の差分は使わない。
    ///
    /// flag のキー名は Terraria のフィールド名そのままとし、Achievement Key へ
    /// 変換しない（変換は PHP の責務: docs/design.md §8.1）。
    /// </summary>
    public sealed class TerrariaWorldObserver
    {
        private readonly AdapterSettings _settings;

        public TerrariaWorldObserver(AdapterSettings settings)
        {
            if (settings == null)
            {
                throw new ArgumentNullException("settings");
            }

            _settings = settings;
        }

        public string WorldKey
        {
            get { return WorldKeyFactory.Create(Main.worldID, _settings.WorldKeyOverride); }
        }

        /// <summary>
        /// Chest ID が「設定名と一致する Collection Chest」かどうか。
        /// 固定文字列ではなく設定値と比較する (docs/design.md §7.1)。
        /// </summary>
        public bool IsCollectionChest(int chestId)
        {
            if (chestId < 0 || Main.chest == null || chestId >= Main.chest.Length)
            {
                return false;
            }

            return Matches(Main.chest[chestId]);
        }

        /// <summary>
        /// World 進捗フラグと、設定名と一致する Collection Chest 全件の現在内容を読む。
        /// </summary>
        public WorldObservation Capture(DateTimeOffset observedAt)
        {
            var flags = new List<KeyValuePair<string, bool>>();

            // docs/design.md §8.1 の対応表にある raw state をそのまま送る。
            flags.Add(new KeyValuePair<string, bool>("downedBoss1", NPC.downedBoss1));
            flags.Add(new KeyValuePair<string, bool>("downedBoss3", NPC.downedBoss3));
            flags.Add(new KeyValuePair<string, bool>("hardMode", Main.hardMode));
            flags.Add(new KeyValuePair<string, bool>("downedMechBoss1", NPC.downedMechBoss1));
            flags.Add(new KeyValuePair<string, bool>("downedMechBoss2", NPC.downedMechBoss2));
            flags.Add(new KeyValuePair<string, bool>("downedMechBoss3", NPC.downedMechBoss3));
            flags.Add(new KeyValuePair<string, bool>("downedPlantBoss", NPC.downedPlantBoss));
            flags.Add(new KeyValuePair<string, bool>("downedGolemBoss", NPC.downedGolemBoss));
            flags.Add(new KeyValuePair<string, bool>("downedAncientCultist", NPC.downedAncientCultist));
            flags.Add(new KeyValuePair<string, bool>("downedMoonlord", NPC.downedMoonlord));

            var chests = new List<ObservedChest>();
            var all = Main.chest;

            if (all != null)
            {
                for (var i = 0; i < all.Length; i++)
                {
                    var chest = all[i];

                    if (!Matches(chest))
                    {
                        continue;
                    }

                    chests.Add(new ObservedChest(chest.x, chest.y, _settings.CollectionChestName, ReadItems(chest)));
                }
            }

            return new WorldObservation(
                WorldKey,
                Main.worldID,
                Main.worldName,
                _settings.CollectionChestName,
                flags,
                chests,
                observedAt);
        }

        private static IEnumerable<ObservedItem> ReadItems(Chest chest)
        {
            var items = new List<ObservedItem>();
            var slots = chest.item;

            if (slots == null)
            {
                return items;
            }

            for (var slot = 0; slot < slots.Length; slot++)
            {
                var item = slots[slot];

                if (item == null || item.type <= 0 || item.stack <= 0)
                {
                    continue;
                }

                // Prefix・数量の累積・slot 位置は Achievement の識別条件ではない
                // (docs/spec.md §5.1)。type / stack と診断用の表示名だけを送る。
                items.Add(new ObservedItem(item.type, item.stack, item.name));
            }

            return items;
        }

        private bool Matches(Chest chest)
        {
            return chest != null
                && string.Equals(chest.name, _settings.CollectionChestName, StringComparison.Ordinal);
        }
    }
}
