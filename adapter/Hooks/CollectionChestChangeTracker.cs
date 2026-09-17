#nullable disable
using System;
using System.Collections.Generic;

namespace TerrariaBacklog.Adapter.Hooks
{
    /// <summary>
    /// 1 回の debounce window で観測した Collection Chest の変更契機。
    /// </summary>
    public sealed class CollectionChangeTrigger
    {
        private readonly List<string> _playerNames;

        public CollectionChangeTrigger(IEnumerable<string> playerNames, int unattributedObservations)
        {
            _playerNames = playerNames == null ? new List<string>() : new List<string>(playerNames);
            _playerNames.Sort(StringComparer.Ordinal);
            UnattributedObservations = unattributedObservations;
        }

        /// <summary>重複排除・安定順にした操作 Player 名。</summary>
        public IList<string> PlayerNames
        {
            get { return _playerNames; }
        }

        /// <summary>
        /// 操作 Player を特定できなかった観測の件数。
        /// **推測で <see cref="PlayerNames"/> を埋めない**ため、件数だけを診断用に残す。
        /// </summary>
        public int UnattributedObservations { get; private set; }
    }

    /// <summary>
    /// Collection Chest の「dirty になった可能性」と「操作した player」だけを集める
    /// debounce バッファ (docs/design.md §7.3, tasks/08 §6.2)。
    ///
    /// ここでは Achievement を判定しない。event / packet の差分値も保持しない。
    /// 保持するのは次の3点だけである。
    ///
    /// <list type="number">
    ///   <item>dirty になった可能性のある Chest ID（分かる場合）</item>
    ///   <item>Chest を特定できない経路（Quick Stack）で dirty になった可能性</item>
    ///   <item>その操作を行った player 名</item>
    /// </list>
    ///
    /// packet hook は network thread から呼ばれうるため、このクラスは thread-safe。
    /// 実際の Chest 読み直しは <see cref="TryTakeDueChange"/> を呼んだ game thread 側で行う。
    /// </summary>
    public sealed class CollectionChestChangeTracker
    {
        private readonly object _gate = new object();
        private readonly TimeSpan _debounce;
        private readonly TimeSpan _maxDelay;

        private readonly HashSet<string> _playerNames = new HashSet<string>(StringComparer.Ordinal);
        private readonly HashSet<int> _chestIds = new HashSet<int>();

        private bool _pending;
        private bool _unknownChestTouched;
        private int _unattributedObservations;
        private DateTime _firstMarkUtc;
        private DateTime _lastMarkUtc;

        public CollectionChestChangeTracker(int debounceMilliseconds, int maxDelayMilliseconds)
        {
            if (debounceMilliseconds < 0)
            {
                throw new ArgumentOutOfRangeException("debounceMilliseconds");
            }

            if (maxDelayMilliseconds < debounceMilliseconds)
            {
                throw new ArgumentOutOfRangeException("maxDelayMilliseconds");
            }

            _debounce = TimeSpan.FromMilliseconds(debounceMilliseconds);
            _maxDelay = TimeSpan.FromMilliseconds(maxDelayMilliseconds);
        }

        public bool HasPending
        {
            get
            {
                lock (_gate)
                {
                    return _pending;
                }
            }
        }

        /// <summary>
        /// Chest ID の分かる変更契機（Terraria packet 32 / <c>PacketTypes.ChestItem</c>）。
        /// 通常ドラッグ・Shift 操作はこちらを通る。
        /// </summary>
        /// <param name="playerName">特定できない場合は null。**推測値を渡さないこと。**</param>
        public void MarkChestSlotChanged(int chestId, string playerName, DateTime nowUtc)
        {
            lock (_gate)
            {
                _chestIds.Add(chestId);
                Mark(playerName, nowUtc);
            }
        }

        /// <summary>
        /// Chest を特定できない変更契機（Terraria packet 85 /
        /// <c>PacketTypes.ForceItemIntoNearestChest</c> = Quick Stack）。
        ///
        /// Quick Stack はサーバー側で「近くの Chest 群」へ分配されるため、
        /// どの Chest が dirty になるかは packet からは分からない。
        /// 設定された Collection Chest を無条件に読み直す契機として扱う。
        /// </summary>
        public void MarkUnknownChestsChanged(string playerName, DateTime nowUtc)
        {
            lock (_gate)
            {
                _unknownChestTouched = true;
                Mark(playerName, nowUtc);
            }
        }

        /// <summary>
        /// debounce が満了していれば、集約した契機を取り出して内部状態を空にする。
        ///
        /// <paramref name="isCollectionChest"/> は game thread 上で
        /// 「その Chest ID が設定名の Collection Chest か」を判定するコールバック。
        /// 設定された Collection Chest が一切関与していない場合は false を返し、
        /// Snapshot を作らない（configured chest 以外は無視する）。
        /// </summary>
        public bool TryTakeDueChange(
            DateTime nowUtc,
            Func<int, bool> isCollectionChest,
            out CollectionChangeTrigger trigger)
        {
            trigger = null;

            List<string> players;
            List<int> chestIds;
            bool unknownChestTouched;
            int unattributed;

            lock (_gate)
            {
                if (!_pending)
                {
                    return false;
                }

                var quiet = nowUtc - _lastMarkUtc >= _debounce;
                var aged = nowUtc - _firstMarkUtc >= _maxDelay;

                if (!quiet && !aged)
                {
                    return false;
                }

                players = new List<string>(_playerNames);
                chestIds = new List<int>(_chestIds);
                unknownChestTouched = _unknownChestTouched;
                unattributed = _unattributedObservations;

                ResetLocked();
            }

            // lock の外で判定する。isCollectionChest は Terraria の state を読むため、
            // hook thread を待たせない。
            var relevant = unknownChestTouched;

            if (!relevant && isCollectionChest != null)
            {
                for (var i = 0; i < chestIds.Count; i++)
                {
                    if (isCollectionChest(chestIds[i]))
                    {
                        relevant = true;
                        break;
                    }
                }
            }

            if (!relevant)
            {
                return false;
            }

            trigger = new CollectionChangeTrigger(players, unattributed);

            return true;
        }

        public void Reset()
        {
            lock (_gate)
            {
                ResetLocked();
            }
        }

        private void Mark(string playerName, DateTime nowUtc)
        {
            if (!_pending)
            {
                _pending = true;
                _firstMarkUtc = nowUtc;
            }

            _lastMarkUtc = nowUtc;

            if (playerName != null && playerName.Length > 0)
            {
                _playerNames.Add(playerName);
            }
            else
            {
                _unattributedObservations++;
            }
        }

        private void ResetLocked()
        {
            _pending = false;
            _unknownChestTouched = false;
            _unattributedObservations = 0;
            _playerNames.Clear();
            _chestIds.Clear();
            _firstMarkUtc = default(DateTime);
            _lastMarkUtc = default(DateTime);
        }
    }
}
