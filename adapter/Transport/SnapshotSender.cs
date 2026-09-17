#nullable disable
using System;
using System.Collections.Generic;
using System.Globalization;
using System.Threading;
using TerrariaBacklog.Adapter.Configuration;
using TerrariaBacklog.Adapter.Logging;
using TerrariaBacklog.Adapter.Snapshots;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// single-flight な Snapshot sender (docs/design.md §7.6, §12, AC-18)。
    ///
    /// 設計上の約束:
    ///
    /// <list type="bullet">
    ///   <item>game thread で HTTP 完了を待たない。送信は worker thread が行う。</item>
    ///   <item>HTTP request を同時に複数走らせない。</item>
    ///   <item>失敗しても Terraria server を止めない（例外を外へ出さない）。</item>
    ///   <item>Adapter 自身が成功 ACK を生成しない。表示するのは PHP が返した通知だけ。</item>
    ///   <item>ACK context（操作 Player）は要求の終了で破棄し、後続の periodic / manual へ
    ///     引き継がない。永続化もしない。</item>
    /// </list>
    /// </summary>
    public sealed class SnapshotSender : IDisposable
    {
        private const int MaxQueuedNotifications = 256;

        /// <summary>まだ1件も通知を取り出していないことを表す世代値。</summary>
        private const long NoGeneration = -1L;

        private readonly AdapterSettings _settings;
        private readonly RuntimeVersions _runtime;
        private readonly ISnapshotTransport _transport;
        private readonly IAdapterLog _log;
        private readonly Func<Guid> _requestIdFactory;

        private readonly SnapshotDispatchQueue _queue = new SnapshotDispatchQueue();
        private readonly Queue<QueuedNotification> _notifications = new Queue<QueuedNotification>();
        private readonly object _notificationGate = new object();
        private readonly ManualResetEvent _workAvailable = new ManualResetEvent(false);

        private int _sending;

        /// <summary>
        /// 失敗の世代。失敗のたびに増える。<see cref="_clearedGeneration"/> と一致していれば
        /// 復旧待ちは無い。フラグを「立てた要求」と「解除する通知」を紐付けるために
        /// bool ではなく世代番号で持つ (docs/design.md §15.2)。
        /// </summary>
        private long _failureGeneration;

        private long _clearedGeneration;

        /// <summary>
        /// 直近の drain で取り出した通知に刻まれていた世代の最小値。
        /// <see cref="NoGeneration"/> なら取り出していない。
        /// </summary>
        private long _dispatchGeneration = NoGeneration;

        private volatile bool _stopping;
        private Thread _worker;

        public SnapshotSender(
            AdapterSettings settings,
            RuntimeVersions runtime,
            ISnapshotTransport transport,
            IAdapterLog log)
            : this(settings, runtime, transport, log, null)
        {
        }

        public SnapshotSender(
            AdapterSettings settings,
            RuntimeVersions runtime,
            ISnapshotTransport transport,
            IAdapterLog log,
            Func<Guid> requestIdFactory)
        {
            if (settings == null)
            {
                throw new ArgumentNullException("settings");
            }

            if (runtime == null)
            {
                throw new ArgumentNullException("runtime");
            }

            if (transport == null)
            {
                throw new ArgumentNullException("transport");
            }

            _settings = settings;
            _runtime = runtime;
            _transport = transport;
            _log = log ?? NullAdapterLog.Instance;
            _requestIdFactory = requestIdFactory ?? NewRequestId;
        }

        /// <summary>
        /// docs/design.md §15.2 の復旧待ちフラグ。Player 情報を含まない world 単位の
        /// メモリ上フラグで、永続化しない。
        /// </summary>
        public bool RecoveryPending
        {
            get { return Interlocked.Read(ref _failureGeneration) != Interlocked.Read(ref _clearedGeneration); }
        }

        public int PendingCount
        {
            get { return _queue.PendingCount; }
        }

        public bool HasPendingCollectionChange
        {
            get { return _queue.HasPendingCollectionChange; }
        }

        /// <summary>game thread から呼ぶ。HTTP は待たない。</summary>
        public void Enqueue(PendingSnapshot snapshot)
        {
            _queue.Enqueue(snapshot);
            _workAvailable.Set();
        }

        /// <summary>
        /// 送信待ちが1件あれば送る。single-flight のため、
        /// すでに送信中なら何もせず false を返す。
        /// </summary>
        /// <returns>送信を1件実行した場合 true。</returns>
        public bool PumpOnce()
        {
            if (Interlocked.CompareExchange(ref _sending, 1, 0) != 0)
            {
                // 別 thread が送信中。同時に複数の HTTP request を走らせない。
                return false;
            }

            try
            {
                PendingSnapshot pending;

                if (!_queue.TryDequeue(out pending))
                {
                    return false;
                }

                Send(pending);

                return true;
            }
            catch (Exception ex)
            {
                // 通信・整形のどの失敗でも Terraria server を止めない。
                _log.Error("snapshot send failed unexpectedly: " + ex.Message);
                MarkRecoveryPending();

                return true;
            }
            finally
            {
                Interlocked.Exchange(ref _sending, 0);
            }
        }

        private void Send(PendingSnapshot pending)
        {
            var recoveryPending = RecoveryPending && SnapshotReason.CarriesRecoveryPending(pending.Reason);

            var envelope = new SnapshotEnvelope(
                _requestIdFactory(),
                pending.Reason,
                _runtime,
                pending.Observation,
                pending.PlayerNames,
                recoveryPending);

            var url = _settings.BuildSnapshotUrl(envelope.WorldKey);

            var result = _transport.Send(url, _settings.AdapterToken, envelope.ToJson());

            // ここから先 pending は参照しない。ACK context は要求の終了で破棄する。
            if (result == null)
            {
                _log.Error("snapshot transport returned no result; treating it as a failure.");
                MarkRecoveryPending();

                return;
            }

            if (!result.Completed)
            {
                _log.Error(string.Format(
                    CultureInfo.InvariantCulture,
                    "snapshot request did not complete (reason={0}): {1}",
                    envelope.Reason,
                    result.Error));
                MarkRecoveryPending();

                return;
            }

            if (!result.IsSuccess)
            {
                _log.Error(string.Format(
                    CultureInfo.InvariantCulture,
                    "bridge rejected the snapshot (reason={0}, status={1}).",
                    envelope.Reason,
                    result.StatusCode));
                MarkRecoveryPending();

                return;
            }

            SnapshotResponse response;
            string error;

            if (!SnapshotResponseReader.TryRead(result.Body, out response, out error))
            {
                // 成功応答だが読めない。Adapter が成功 ACK を捏造しない。
                // この要求の結果は不明なので復旧待ちとして扱い、次の periodic / manual に
                // recoveryPending を載せる (docs/design.md §15.2 / AC-09)。
                _log.Error("bridge response could not be read: " + error);
                MarkRecoveryPending();

                return;
            }

            var expectedRequestId = envelope.RequestId.ToString("D");

            if (!response.Matches(expectedRequestId, envelope.WorldKey))
            {
                // 送信した envelope に対応しない応答。古い応答や別 world の応答を
                // そのまま表示すると、別要求の通知を誤った Player へ出しかねない。
                // requestId / worldKey は診断に残すが、通知は捨てる。
                _log.Error(string.Format(
                    CultureInfo.InvariantCulture,
                    "bridge response does not match the in-flight request "
                    + "(expected requestId={0} worldKey={1}, got requestId={2} worldKey={3}); "
                    + "the notifications are dropped.",
                    expectedRequestId,
                    envelope.WorldKey,
                    response.RequestId,
                    response.WorldKey));
                MarkRecoveryPending();

                return;
            }

            EnqueueNotifications(response.Notifications);
        }

        private void EnqueueNotifications(IList<AdapterNotification> notifications)
        {
            if (notifications == null || notifications.Count == 0)
            {
                return;
            }

            // single-flight なので、ここで読む世代はこの応答を受け取った時点の世代である。
            // 後続の失敗で世代が進めば、この通知では復旧待ちを解除しない。
            var generation = Interlocked.Read(ref _failureGeneration);

            lock (_notificationGate)
            {
                for (var i = 0; i < notifications.Count; i++)
                {
                    if (_notifications.Count >= MaxQueuedNotifications)
                    {
                        _log.Warn("notification buffer is full; dropping the oldest entry.");
                        _notifications.Dequeue();
                    }

                    _notifications.Enqueue(new QueuedNotification(notifications[i], generation));
                }
            }
        }

        /// <summary>
        /// game thread から表示待ちの通知を取り出す。
        /// 表示そのものは TShock 側の sink が行う。
        /// </summary>
        public bool TryDequeueNotification(out AdapterNotification notification)
        {
            lock (_notificationGate)
            {
                if (_notifications.Count == 0)
                {
                    notification = null;

                    return false;
                }

                var queued = _notifications.Dequeue();
                notification = queued.Notification;

                // 同じ drain で複数世代の通知が混ざった場合は、古い方に合わせて
                // 解除判定する（新しい失敗を隠さない側に倒す）。
                if (_dispatchGeneration == NoGeneration || queued.Generation < _dispatchGeneration)
                {
                    _dispatchGeneration = queued.Generation;
                }

                return true;
            }
        }

        /// <summary>
        /// docs/design.md §15.2: 復旧完了通知を console へ表示したらフラグを解除する。
        /// 未達・部分失敗（= server 向け通知が返らない）では解除しない。
        ///
        /// 解除は「その通知を確立した要求の世代」に紐付ける。通知を enqueue した後に
        /// 別の要求が失敗していた場合は世代が進んでいるので解除せず、
        /// 新しい失敗を古い通知の表示で隠さない。
        /// </summary>
        /// <param name="displayedServerNotification">
        /// <c>audience=server</c> の通知を1件以上 console へ表示したか。
        /// </param>
        public void OnNotificationsDispatched(bool displayedServerNotification)
        {
            long dispatched;

            lock (_notificationGate)
            {
                dispatched = _dispatchGeneration;
                _dispatchGeneration = NoGeneration;
            }

            if (!displayedServerNotification || dispatched == NoGeneration)
            {
                return;
            }

            var current = Interlocked.Read(ref _failureGeneration);

            if (dispatched != current)
            {
                // 通知が積まれた後にも失敗している。復旧は完了していない。
                _log.Warn("a newer snapshot failed after the recovery notification was queued; "
                    + "the recovery flag stays set.");

                return;
            }

            Interlocked.Exchange(ref _clearedGeneration, current);
        }

        /// <summary>
        /// <see cref="OnNotificationsDispatched(bool)"/> の
        /// 「server 向け通知を表示した」ケース。
        /// </summary>
        public void OnRecoveryNotificationDisplayed()
        {
            OnNotificationsDispatched(true);
        }

        private void MarkRecoveryPending()
        {
            Interlocked.Increment(ref _failureGeneration);
        }

        /// <summary>表示待ち通知と、それを確立した要求の世代。</summary>
        private struct QueuedNotification
        {
            public QueuedNotification(AdapterNotification notification, long generation)
            {
                Notification = notification;
                Generation = generation;
            }

            public readonly AdapterNotification Notification;

            public readonly long Generation;
        }

        // ------------------------------------------------------------------
        // worker thread
        // ------------------------------------------------------------------

        public void Start()
        {
            if (_worker != null)
            {
                return;
            }

            _stopping = false;
            _worker = new Thread(RunWorkerLoop);
            _worker.IsBackground = true;
            _worker.Name = "TerrariaBacklog.Adapter.SnapshotSender";
            _worker.Start();
        }

        public void Stop()
        {
            _stopping = true;
            _workAvailable.Set();

            var worker = _worker;
            _worker = null;

            if (worker != null)
            {
                try
                {
                    worker.Join(TimeSpan.FromSeconds(5));
                }
                catch (Exception)
                {
                    // shutdown 経路で例外を伝播させない。
                }
            }
        }

        private void RunWorkerLoop()
        {
            while (!_stopping)
            {
                bool sentSomething;

                try
                {
                    sentSomething = PumpOnce();
                }
                catch (Exception ex)
                {
                    _log.Error("snapshot worker loop error: " + ex.Message);
                    sentSomething = false;
                }

                if (sentSomething)
                {
                    continue;
                }

                _workAvailable.Reset();

                if (_queue.PendingCount > 0)
                {
                    // Reset と Enqueue が競合した場合の取りこぼし防止。
                    continue;
                }

                _workAvailable.WaitOne(250);
            }
        }

        public void Dispose()
        {
            Stop();

            try
            {
                _workAvailable.Close();
            }
            catch (Exception)
            {
                // Dispose 経路で例外を伝播させない。
            }
        }

        private static Guid NewRequestId()
        {
            return Guid.NewGuid();
        }
    }
}
