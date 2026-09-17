#nullable disable
using System;

namespace TerrariaBacklog.Adapter.Snapshots
{
    /// <summary>
    /// contracts/snapshot-v1.schema.json の <c>reason</c> (docs/design.md §6.3)。
    ///
    /// PHP の達成判定は reason に依存しない。Adapter 側も reason から
    /// Achievement を推測しない。用途は診断・ACK 表示・観測契機の把握だけ。
    /// </summary>
    public static class SnapshotReason
    {
        public const string Startup = "startup";
        public const string Periodic = "periodic";
        public const string CollectionChange = "collection_change";
        public const string WorldChange = "world_change";
        public const string Manual = "manual";

        public static bool IsValid(string reason)
        {
            return string.Equals(reason, Startup, StringComparison.Ordinal)
                || string.Equals(reason, Periodic, StringComparison.Ordinal)
                || string.Equals(reason, CollectionChange, StringComparison.Ordinal)
                || string.Equals(reason, WorldChange, StringComparison.Ordinal)
                || string.Equals(reason, Manual, StringComparison.Ordinal);
        }

        /// <summary>
        /// docs/design.md §7.6: <c>periodic</c> / <c>world_change</c> / <c>startup</c> の
        /// 状態 Snapshot だけが未送信同士で最新状態へ coalesce してよい。
        /// </summary>
        public static bool IsCoalescableState(string reason)
        {
            return string.Equals(reason, Startup, StringComparison.Ordinal)
                || string.Equals(reason, Periodic, StringComparison.Ordinal)
                || string.Equals(reason, WorldChange, StringComparison.Ordinal);
        }

        /// <summary>
        /// docs/design.md §15.2: 復旧待ちフラグを載せるのは periodic / manual だけ。
        /// </summary>
        public static bool CarriesRecoveryPending(string reason)
        {
            return string.Equals(reason, Periodic, StringComparison.Ordinal)
                || string.Equals(reason, Manual, StringComparison.Ordinal);
        }
    }
}
