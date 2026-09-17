#nullable disable
using System;
using Terraria;
using TerrariaApi.Server;
using TerrariaBacklog.Adapter.Logging;
using TShockAPI;

namespace TerrariaBacklog.Adapter.Hooks
{
    /// <summary>
    /// Collection Chest の変更契機を観測する薄い hook 層
    /// (docs/design.md §7.3, tasks/08 §6.1)。
    ///
    /// <para><b>採用した hook: <c>ServerApi.Hooks.NetGetData</c>（packet 32 / 85）</b></para>
    ///
    /// TShock 4.3.13 / Terraria 1.3.0.8 の実バイナリを逆コンパイルして確認した事実:
    ///
    /// <list type="number">
    ///   <item>
    ///     <c>TShockAPI.GetDataHandlers.ChestItemEventArgs</c> は
    ///     <c>ID / Slot / Stacks / Prefix / Type</c> しか持たず、
    ///     <b>操作した player を特定できない</b>。
    ///     <c>GetDataHandlers.OnChestItemChange(short, byte, short, byte, short)</c> の
    ///     引数にも player が無い。よって <c>collection_change</c> に必要な
    ///     <c>trigger.playerNames</c> をこの event 単体では満たせない。
    ///   </item>
    ///   <item>
    ///     <c>GetDataHandlers.InitGetDataHandler()</c> が登録する packet は
    ///     31 / 32 / 33 等であり、<b>Quick Stack (85) のハンドラは存在しない</b>。
    ///     つまり Quick Stack では <c>ChestItemChange</c> も <c>ChestOpen</c> も発火しない。
    ///   </item>
    ///   <item>
    ///     Quick Stack は <c>Terraria.MessageBuffer.GetData</c> の <c>case 85</c> で
    ///     <c>Chest.ServerPlaceItem(whoAmI, slot)</c> を呼び、
    ///     <c>Chest.PutItemInNearbyChest</c> がサーバー側で直接
    ///     <c>Main.chest[i].item[j]</c> を書き換える。Chest を開かないため
    ///     <c>TSPlayer.ActiveChest</c> による attribution も効かない。
    ///   </item>
    ///   <item>
    ///     <c>MessageBuffer.GetData</c> は vanilla の処理に入る前に
    ///     <c>ServerApi.Hooks.InvokeNetGetData(ref msgId, this, ref index, ref length)</c> を呼ぶ。
    ///     <c>GetDataEventArgs.Msg.whoAmI</c> が<b>送信元 player index</b> であり、
    ///     packet 32 / 85 の両方で player を特定できる。
    ///     <c>PacketTypes</c> (TerrariaServer.exe, global namespace) の値は
    ///     <c>ChestItem = 32</c> / <c>ForceItemIntoNearestChest = 85</c>
    ///     (= <c>Terraria.ID.MessageID.QuickStackChests</c>) で、いずれも
    ///     <c>Enum.IsDefined</c> を通るため hook が呼ばれる。
    ///   </item>
    ///   <item>
    ///     <c>HandlerCollection.Invoke</c> は <c>Handled</c> で打ち切らず全ハンドラを呼び、
    ///     ハンドラ内の例外も catch してログするだけである。よって他 plugin の
    ///     判断に左右されず観測でき、こちらの例外でサーバーも止まらない。
    ///   </item>
    /// </list>
    ///
    /// この hook が読み取るのは「Collection Chest が dirty になった可能性」と
    /// 「操作した player」の2点だけで、packet の差分値は Snapshot に流用しない
    /// (tasks/08 §6.2)。実際の内容は debounce 後に game thread で読み直す。
    /// </summary>
    public sealed class ChestChangeWatcher
    {
        /// <summary>
        /// Terraria 1.3.0.8 の <c>SyncChestItem</c>。TShock の <c>PacketTypes.ChestItem</c>。
        /// 通常ドラッグ / Shift 操作はこの packet で送られる。
        /// </summary>
        public const int ChestItemPacket = 32;

        /// <summary>
        /// Terraria 1.3.0.8 の <c>MessageID.QuickStackChests</c>。
        /// TShock の <c>PacketTypes.ForceItemIntoNearestChest</c>。
        /// </summary>
        public const int QuickStackPacket = 85;

        /// <summary>TShock 本体より先に観測するための priority。</summary>
        private const int HookPriority = 1;

        private readonly TerrariaPlugin _plugin;
        private readonly CollectionChestChangeTracker _tracker;
        private readonly IAdapterLog _log;
        private readonly Func<DateTime> _clock;
        private HookHandler<GetDataEventArgs> _handler;

        public ChestChangeWatcher(
            TerrariaPlugin plugin,
            CollectionChestChangeTracker tracker,
            IAdapterLog log,
            Func<DateTime> clock)
        {
            if (plugin == null)
            {
                throw new ArgumentNullException("plugin");
            }

            if (tracker == null)
            {
                throw new ArgumentNullException("tracker");
            }

            _plugin = plugin;
            _tracker = tracker;
            _log = log ?? NullAdapterLog.Instance;
            _clock = clock ?? DefaultClock;
        }

        public void Register()
        {
            if (_handler != null)
            {
                return;
            }

            _handler = OnNetGetData;
            ServerApi.Hooks.NetGetData.Register(_plugin, _handler, HookPriority);
        }

        public void Deregister()
        {
            if (_handler == null)
            {
                return;
            }

            ServerApi.Hooks.NetGetData.Deregister(_plugin, _handler);
            _handler = null;
        }

        private void OnNetGetData(GetDataEventArgs args)
        {
            // network thread から呼ばれうる。ここでは Terraria state を読まない。
            try
            {
                if (args == null || args.Msg == null)
                {
                    return;
                }

                var packet = (int)args.MsgID;

                if (packet != ChestItemPacket && packet != QuickStackPacket)
                {
                    return;
                }

                var playerName = ResolvePlayerName(args.Msg.whoAmI);
                var now = _clock();

                if (packet == QuickStackPacket)
                {
                    // Quick Stack はサーバー側が「近くの Chest 群」へ分配するため、
                    // どの Chest が dirty になるか packet からは決まらない。
                    _tracker.MarkUnknownChestsChanged(playerName, now);

                    return;
                }

                int chestId;

                if (!TryReadChestId(args, out chestId))
                {
                    // Chest ID を読めない場合も「どこかが dirty」として扱い、
                    // 取りこぼしよりも読み直しを優先する。
                    _tracker.MarkUnknownChestsChanged(playerName, now);

                    return;
                }

                _tracker.MarkChestSlotChanged(chestId, playerName, now);
            }
            catch (Exception ex)
            {
                // hook の失敗でサーバーを止めない。
                _log.Error("chest change hook failed: " + ex.Message);
            }
        }

        /// <summary>
        /// packet 32 の payload 先頭 2byte が chest ID (Int16)。
        /// <c>GetDataEventArgs.Index</c> は msgId の直後を指す
        /// (<c>MessageBuffer.GetData</c>: <c>num = start + 1</c>)。
        /// </summary>
        private static bool TryReadChestId(GetDataEventArgs args, out int chestId)
        {
            chestId = -1;

            var buffer = args.Msg.readBuffer;

            if (buffer == null || args.Index < 0 || args.Index + 2 > buffer.Length)
            {
                return false;
            }

            chestId = BitConverter.ToInt16(buffer, args.Index);

            return chestId >= 0;
        }

        /// <summary>
        /// 送信元 player index から名前を得る。
        /// **特定できない場合は null を返し、推測値を作らない** (tasks/08 §6.3)。
        /// </summary>
        private static string ResolvePlayerName(int playerIndex)
        {
            if (playerIndex < 0 || TShock.Players == null || playerIndex >= TShock.Players.Length)
            {
                return null;
            }

            var player = TShock.Players[playerIndex];

            if (player == null || !player.RealPlayer)
            {
                return null;
            }

            var name = player.Name;

            return string.IsNullOrEmpty(name) ? null : name;
        }

        private static DateTime DefaultClock()
        {
            return DateTime.UtcNow;
        }
    }
}
