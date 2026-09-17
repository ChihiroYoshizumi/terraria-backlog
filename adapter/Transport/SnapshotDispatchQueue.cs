#nullable disable
using System;
using System.Collections.Generic;
using TerrariaBacklog.Adapter.Snapshots;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// 送信待ちの Snapshot 1件。requestId / recoveryPending は送信直前に決めるため持たない。
    /// </summary>
    public sealed class PendingSnapshot
    {
        private readonly List<string> _playerNames;

        public PendingSnapshot(string reason, WorldObservation observation, IEnumerable<string> playerNames)
        {
            if (!SnapshotReason.IsValid(reason))
            {
                throw new ArgumentException("unsupported snapshot reason: " + reason, "reason");
            }

            if (observation == null)
            {
                throw new ArgumentNullException("observation");
            }

            Reason = reason;
            Observation = observation;
            _playerNames = playerNames == null ? new List<string>() : new List<string>(playerNames);
        }

        public string Reason { get; private set; }

        public WorldObservation Observation { get; private set; }

        /// <summary>
        /// ACK routing 情報 (docs/design.md §7.6)。プロセス内メモリにだけ存在し、
        /// 要求の終了で破棄される。永続化しない。
        /// </summary>
        public IList<string> PlayerNames
        {
            get { return _playerNames; }
        }

        /// <summary>直近の観測で置き換えつつ、Player 名は集合として保持する。</summary>
        internal PendingSnapshot CoalesceWith(PendingSnapshot newer)
        {
            var names = new List<string>(_playerNames);

            for (var i = 0; i < newer._playerNames.Count; i++)
            {
                if (!names.Contains(newer._playerNames[i]))
                {
                    names.Add(newer._playerNames[i]);
                }
            }

            names.Sort(StringComparer.Ordinal);

            return new PendingSnapshot(newer.Reason, newer.Observation, names);
        }
    }

    /// <summary>
    /// 未送信 Snapshot の coalescing (docs/design.md §7.6)。
    ///
    /// 保持するのは最大3スロットで、永続 Queue は作らない。プロセス終了で失われてよい。
    ///
    /// <list type="bullet">
    ///   <item><c>collection_change</c>: ACK routing 情報を持つので periodic 等で破棄しない。
    ///     連続する場合は最新 Chest state に更新しつつ playerNames を集合として保持する。</item>
    ///   <item><c>manual</c>: 管理者の明示操作なので状態 Snapshot に吸収させない。
    ///     未送信の manual 同士だけ最新状態へ畳む。</item>
    ///   <item><c>startup</c> / <c>periodic</c> / <c>world_change</c>: 未送信同士なら
    ///     最新状態へ coalesce する。</item>
    /// </list>
    /// </summary>
    public sealed class SnapshotDispatchQueue
    {
        private readonly object _gate = new object();

        private PendingSnapshot _collectionChange;
        private PendingSnapshot _manual;
        private PendingSnapshot _state;

        public void Enqueue(PendingSnapshot snapshot)
        {
            if (snapshot == null)
            {
                throw new ArgumentNullException("snapshot");
            }

            lock (_gate)
            {
                if (string.Equals(snapshot.Reason, SnapshotReason.CollectionChange, StringComparison.Ordinal))
                {
                    _collectionChange = _collectionChange == null
                        ? snapshot
                        : _collectionChange.CoalesceWith(snapshot);

                    return;
                }

                if (string.Equals(snapshot.Reason, SnapshotReason.Manual, StringComparison.Ordinal))
                {
                    _manual = snapshot;

                    return;
                }

                // startup / periodic / world_change
                _state = snapshot;
            }
        }

        /// <summary>
        /// 送信対象を1件取り出す。
        /// ACK 宛先を持つ <c>collection_change</c> を最優先、次に管理者操作の
        /// <c>manual</c>、最後に状態 Snapshot の順にする。
        /// </summary>
        public bool TryDequeue(out PendingSnapshot snapshot)
        {
            lock (_gate)
            {
                if (_collectionChange != null)
                {
                    snapshot = _collectionChange;
                    _collectionChange = null;

                    return true;
                }

                if (_manual != null)
                {
                    snapshot = _manual;
                    _manual = null;

                    return true;
                }

                if (_state != null)
                {
                    snapshot = _state;
                    _state = null;

                    return true;
                }
            }

            snapshot = null;

            return false;
        }

        public int PendingCount
        {
            get
            {
                lock (_gate)
                {
                    var count = 0;

                    if (_collectionChange != null)
                    {
                        count++;
                    }

                    if (_manual != null)
                    {
                        count++;
                    }

                    if (_state != null)
                    {
                        count++;
                    }

                    return count;
                }
            }
        }

        public bool HasPendingCollectionChange
        {
            get
            {
                lock (_gate)
                {
                    return _collectionChange != null;
                }
            }
        }

        public void Clear()
        {
            lock (_gate)
            {
                _collectionChange = null;
                _manual = null;
                _state = null;
            }
        }
    }
}
