#nullable disable
using System;
using System.Collections.Generic;
using TerrariaBacklog.Adapter.Logging;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// 通知の実際の出力先。TShock 依存はこの実装側に閉じる。
    /// </summary>
    public interface INotificationSink
    {
        /// <summary>指定 Player へ表示する。オフライン等で表示できなければ false。</summary>
        bool SendToPlayer(string playerName, string message);

        /// <summary>server console だけへ出力する。ゲーム内全体チャットへは出さない。</summary>
        void SendToServerConsole(string message);
    }

    /// <summary>
    /// PHP が決めた通知命令をそのまま表示する (docs/design.md §6.5, §15.1)。
    ///
    /// Adapter は message や宛先を再判定しない。ここがやるのは
    /// <c>audience</c> による出力先の振り分けだけである。
    /// </summary>
    public sealed class NotificationDispatcher
    {
        private readonly INotificationSink _sink;
        private readonly IAdapterLog _log;

        public NotificationDispatcher(INotificationSink sink, IAdapterLog log)
        {
            if (sink == null)
            {
                throw new ArgumentNullException("sink");
            }

            _sink = sink;
            _log = log ?? NullAdapterLog.Instance;
        }

        /// <summary>
        /// 通知を表示する。
        /// </summary>
        /// <returns>
        /// <c>audience=server</c> の通知を1件以上 console へ表示した場合 true。
        /// 復旧待ちフラグの解除契機に使う (docs/design.md §15.2)。
        /// </returns>
        public bool Dispatch(IEnumerable<AdapterNotification> notifications)
        {
            if (notifications == null)
            {
                return false;
            }

            var displayedServerNotification = false;

            foreach (var notification in notifications)
            {
                if (notification == null)
                {
                    continue;
                }

                if (notification.IsForServerConsole)
                {
                    // docs/design.md §6.5: server は server console のみ。
                    // playerNames が付いていても Player へは配らない。
                    _sink.SendToServerConsole(notification.Message);
                    displayedServerNotification = true;
                    continue;
                }

                if (notification.IsForPlayers)
                {
                    if (notification.PlayerNames.Count == 0)
                    {
                        // 宛先不明を全体チャットへ広げない。Adapter が宛先を補完しない。
                        _log.Warn("dropped a players-audience notification without playerNames.");
                        continue;
                    }

                    for (var i = 0; i < notification.PlayerNames.Count; i++)
                    {
                        var name = notification.PlayerNames[i];

                        if (!_sink.SendToPlayer(name, notification.Message))
                        {
                            _log.Info("notification recipient is not online; skipped.");
                        }
                    }

                    continue;
                }

                // 未知の audience を勝手に解釈しない。
                _log.Warn("dropped a notification with an unsupported audience.");
            }

            return displayedServerNotification;
        }
    }
}
