#nullable disable
using System;
using System.Collections.Generic;

namespace TerrariaBacklog.Adapter.Snapshots
{
    /// <summary>
    /// Collection Chest の 1 slot から観測した Item。
    ///
    /// **event 引数の差分ではなく、game thread で読み直した最終状態**だけを入れる
    /// (docs/design.md §7.3)。Achievement 判定はしない。
    /// </summary>
    public sealed class ObservedItem
    {
        public ObservedItem(int type, int stack, string name)
        {
            Type = type;
            Stack = stack;
            Name = name;
        }

        public int Type { get; private set; }

        public int Stack { get; private set; }

        /// <summary>診断用の表示名。PHP の判定には使われない。</summary>
        public string Name { get; private set; }
    }

    /// <summary>
    /// 設定名と一致した Collection Chest 1件の現在内容。
    /// 座標は観測情報であり恒久的な識別条件ではない (docs/spec.md §6)。
    /// </summary>
    public sealed class ObservedChest
    {
        private readonly List<ObservedItem> _items;

        public ObservedChest(int x, int y, string name, IEnumerable<ObservedItem> items)
        {
            X = x;
            Y = y;
            Name = name;
            _items = items == null ? new List<ObservedItem>() : new List<ObservedItem>(items);
        }

        public int X { get; private set; }

        public int Y { get; private set; }

        public string Name { get; private set; }

        public IList<ObservedItem> Items
        {
            get { return _items; }
        }
    }

    /// <summary>
    /// game thread 上で読み取った World / Chest の現在状態 (docs/design.md §7.6)。
    /// HTTP はこの DTO をバックグラウンドで送るだけにする。
    /// </summary>
    public sealed class WorldObservation
    {
        private readonly List<KeyValuePair<string, bool>> _flags;
        private readonly List<ObservedChest> _chests;

        public WorldObservation(
            string worldKey,
            int terrariaWorldId,
            string worldName,
            string collectionChestName,
            IEnumerable<KeyValuePair<string, bool>> flags,
            IEnumerable<ObservedChest> chests,
            DateTimeOffset observedAt)
        {
            if (worldKey == null)
            {
                throw new ArgumentNullException("worldKey");
            }

            if (collectionChestName == null)
            {
                throw new ArgumentNullException("collectionChestName");
            }

            WorldKey = worldKey;
            TerrariaWorldId = terrariaWorldId;
            WorldName = worldName;
            CollectionChestName = collectionChestName;
            ObservedAt = observedAt;
            _flags = flags == null
                ? new List<KeyValuePair<string, bool>>()
                : new List<KeyValuePair<string, bool>>(flags);
            _chests = chests == null ? new List<ObservedChest>() : new List<ObservedChest>(chests);
        }

        public string WorldKey { get; private set; }

        public int TerrariaWorldId { get; private set; }

        /// <summary>診断用。識別子には使わない (docs/specs/world-identity.md)。</summary>
        public string WorldName { get; private set; }

        public string CollectionChestName { get; private set; }

        public DateTimeOffset ObservedAt { get; private set; }

        /// <summary>
        /// raw な World 進捗フラグ。キー名は Terraria.NPC / Terraria.Main の
        /// フィールド名そのままで、Achievement Key へは変換しない。
        /// 出力順を安定させるため順序付きで保持する。
        /// </summary>
        public IList<KeyValuePair<string, bool>> Flags
        {
            get { return _flags; }
        }

        public IList<ObservedChest> CollectionChests
        {
            get { return _chests; }
        }
    }
}
