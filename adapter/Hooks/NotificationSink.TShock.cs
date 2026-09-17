#nullable disable
using System;
using System.Diagnostics;
using TerrariaBacklog.Adapter.Logging;
using TerrariaBacklog.Adapter.Transport;
using TShockAPI;

namespace TerrariaBacklog.Adapter.Hooks
{
    /// <summary>
    /// TShock への通知出力 (docs/design.md §6.5, §15.2)。
    ///
    /// <c>audience=players</c> は指定 Player のみ、<c>audience=server</c> は
    /// **server console のみ**へ出す。ゲーム内の全体チャットへは出さない。
    /// </summary>
    public sealed class TShockNotificationSink : INotificationSink
    {
        /// <summary>game thread から呼ぶこと。</summary>
        public bool SendToPlayer(string playerName, string message)
        {
            if (string.IsNullOrEmpty(playerName) || message == null)
            {
                return false;
            }

            var players = TShock.Players;

            if (players == null)
            {
                return false;
            }

            var delivered = false;

            for (var i = 0; i < players.Length; i++)
            {
                var player = players[i];

                if (player == null || !player.Active || !player.ConnectionAlive)
                {
                    continue;
                }

                if (!string.Equals(player.Name, playerName, StringComparison.Ordinal))
                {
                    continue;
                }

                // PHP が確定した文言をそのまま表示する。Adapter 側で加工しない。
                player.SendInfoMessage("{0}", message);
                delivered = true;
            }

            return delivered;
        }

        public void SendToServerConsole(string message)
        {
            if (message == null)
            {
                return;
            }

            var log = TShock.Log;

            if (log == null)
            {
                Console.WriteLine(message);

                return;
            }

            log.ConsoleInfo("{0}", message);
        }
    }

    /// <summary>
    /// TShock の <c>ILog</c> へ委譲する <see cref="IAdapterLog"/>。
    ///
    /// <c>TShock.Log</c> は TShock 本体の <c>Initialize()</c> が動くまで null である。
    /// Plugin の初期化順序によってはそれより前に呼ばれうるため、
    /// null のときはコンソールへ退避する。
    /// **ここで例外を投げるとサーバー起動そのものが中断する**ので、必ず握り潰す。
    /// </summary>
    public sealed class TShockAdapterLog : IAdapterLog
    {
        private const string Prefix = "[TerrariaBacklog.Adapter] ";

        public void Info(string message)
        {
            Write(Prefix + message, TraceLevel.Info);
        }

        public void Warn(string message)
        {
            Write(Prefix + message, TraceLevel.Warning);
        }

        public void Error(string message)
        {
            Write(Prefix + message, TraceLevel.Error);
        }

        private static void Write(string message, TraceLevel level)
        {
            try
            {
                var log = TShock.Log;

                if (log != null)
                {
                    if (level == TraceLevel.Error)
                    {
                        log.ConsoleError("{0}", message);
                    }
                    else if (level == TraceLevel.Warning)
                    {
                        log.Warn("{0}", message);
                    }
                    else
                    {
                        log.ConsoleInfo("{0}", message);
                    }

                    return;
                }

                // TShock 本体の初期化前。ServerApi.LogWriter は internal なので
                // コンソールへ直接書く。
                Console.WriteLine(message);
            }
            catch (Exception)
            {
                try
                {
                    Console.WriteLine(message);
                }
                catch (Exception)
                {
                    // ログ出力の失敗でサーバーを止めない。
                }
            }
        }
    }
}
