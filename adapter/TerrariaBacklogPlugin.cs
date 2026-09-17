#nullable disable
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.Reflection;
using Terraria;
using TerrariaApi.Server;
using TerrariaBacklog.Adapter.Configuration;
using TerrariaBacklog.Adapter.Hooks;
using TerrariaBacklog.Adapter.Logging;
using TerrariaBacklog.Adapter.Runtime;
using TerrariaBacklog.Adapter.Snapshots;
using TerrariaBacklog.Adapter.Transport;
using TShockAPI;

namespace TerrariaBacklog.Adapter
{
    /// <summary>
    /// TShock が ServerPlugins からロードする TerrariaBacklog Adapter のエントリポイント。
    ///
    /// 責務 (docs/design.md §2.2, §3.1):
    ///
    /// <list type="bullet">
    ///   <item>World / Chest の現在状態を観測して Snapshot を作る</item>
    ///   <item>PHP Bridge へ非同期に送る</item>
    ///   <item>PHP が確定した通知をそのまま表示する</item>
    /// </list>
    ///
    /// <b>やらないこと:</b> Achievement 判定、Backlog API 呼び出し、
    /// Registry / Mapping / Issue Key の解釈、永続 Queue / Outbox / 独自 DB。
    /// </summary>
    /// <remarks>
    /// ApiVersion は TShock 4.3.13 (for Terraria 1.3.0.8) 本体が宣言する ServerApi の
    /// バージョン 1.22 に合わせる (docs/design.md §2.3)。値がずれると TShock の
    /// Plugin ロード時互換性チェックで弾かれる。
    /// </remarks>
    [ApiVersion(1, 22)]
    public sealed class TerrariaBacklogPlugin : TerrariaPlugin
    {
        /// <summary>game thread の処理を毎 tick 回さないための間引き間隔。</summary>
        private static readonly TimeSpan UpdateInterval = TimeSpan.FromMilliseconds(100);

        private readonly IAdapterLog _log = new TShockAdapterLog();

        private AdapterSettings _settings;
        private TerrariaWorldObserver _observer;
        private CollectionChestChangeTracker _tracker;
        private ChestChangeWatcher _chestChangeWatcher;
        private SnapshotSender _sender;
        private NotificationDispatcher _dispatcher;
        private PeriodicTrigger _periodic;
        private Command _command;

        private bool _enabled;
        private bool _startupSnapshotSent;
        private volatile bool _manualSyncRequested;
        private DateTime _nextUpdateUtc;

        public override Version Version
        {
            get { return Assembly.GetExecutingAssembly().GetName().Version ?? new Version(0, 1, 0); }
        }

        public override string Name
        {
            get { return "TerrariaBacklog.Adapter"; }
        }

        public override string Author
        {
            get { return "terraria-backlog"; }
        }

        public override string Description
        {
            get
            {
                return "Terraria Backlog Bridge 向けの薄い Adapter。World/Chest 状態の観測結果を PHP へ送信し、" +
                    "PHP が確定した通知を表示するだけの Plugin。Achievement / Backlog のドメインロジックは持たない。";
            }
        }

        public TerrariaBacklogPlugin(Main game)
            : base(game)
        {
            // TerrariaApi.Server.ServerApi.LoadPlugins() は
            // `orderby x.Plugin.Order, x.Plugin.Name` の順で Initialize() を呼ぶ。
            // TShock 本体は Order = 0 / Name = "TShock" であり、Name の文字列比較は
            // culture-aware なため "TerrariaBacklog.Adapter" が先に来てしまう。
            // その時点では TShock.Log / TShock.SavePath 等がまだ初期化されていないので、
            // Order を 1 にして **必ず TShock 本体の後に初期化する**。
            Order = 1;
        }

        /// <summary>
        /// <c>ServerApi.LoadPlugins()</c> は Initialize() の例外を
        /// <c>InvalidOperationException</c> に包んで再送出し、**サーバー起動全体を中断する**。
        /// Adapter の不具合で Terraria server を止めないため、ここで必ず握り潰す。
        /// </summary>
        public override void Initialize()
        {
            try
            {
                InitializeCore();
            }
            catch (Exception ex)
            {
                _enabled = false;
                _log.Error("initialization failed; the adapter stays disabled: " + ex);
            }
        }

        private void InitializeCore()
        {
            // 1. 設定
            string configError;
            _settings = AdapterSettingsFile.LoadOrCreate(TShock.SavePath, out configError);

            if (_settings == null)
            {
                _log.Error("configuration could not be loaded: " + configError);
                _log.Error("hooks are NOT registered and no snapshot will be sent.");

                return;
            }

            var problems = _settings.Validate();

            if (problems.Count > 0)
            {
                // fail closed (docs/design.md §18.4)。設定不備で誤った同期を始めない。
                for (var i = 0; i < problems.Count; i++)
                {
                    _log.Error("configuration problem: " + problems[i]);
                }

                _log.Error("hooks are NOT registered and no snapshot will be sent. Fix "
                    + Path.Combine(TShock.SavePath, AdapterSettingsFile.FileName)
                    + " (or " + AdapterSettingsFile.TokenEnvironmentVariable + ") and restart.");

                return;
            }

            // 2. runtime compatibility gate (docs/design.md §2.3)
            var runtime = DetectRuntimeVersions();
            IList<string> incompatibilities;

            if (!SupportedRuntimeMatrix.IsSupported(
                    runtime.TerrariaVersion,
                    runtime.TShockVersion,
                    out incompatibilities))
            {
                for (var i = 0; i < incompatibilities.Count; i++)
                {
                    _log.Error("unsupported runtime: " + incompatibilities[i]);
                }

                _log.Error("hooks are NOT registered and no snapshot will be sent.");

                return;
            }

            // 3. 組み立て
            _observer = new TerrariaWorldObserver(_settings);
            _tracker = new CollectionChestChangeTracker(
                _settings.ChestChangeDebounceMilliseconds,
                _settings.ChestChangeMaxDelayMilliseconds);
            _sender = new SnapshotSender(
                _settings,
                runtime,
                new HttpSnapshotTransport(_settings.RequestTimeoutSeconds),
                _log);
            _dispatcher = new NotificationDispatcher(new TShockNotificationSink(), _log);
            _periodic = new PeriodicTrigger(_settings.ReconciliationIntervalSeconds);
            _chestChangeWatcher = new ChestChangeWatcher(this, _tracker, _log, null);

            // 4. hook 登録
            ServerApi.Hooks.GamePostInitialize.Register(this, OnGamePostInitialize);
            ServerApi.Hooks.GameUpdate.Register(this, OnGameUpdate);
            _chestChangeWatcher.Register();

            _command = new Command("terrariabacklog.sync", OnBacklogCommand, "backlog");
            _command.HelpText = "/backlog sync - reason=manual の Full Snapshot を PHP Bridge へ即時送信する。";
            Commands.ChatCommands.Add(_command);

            _sender.Start();
            _enabled = true;

            _log.Info(string.Format(
                CultureInfo.InvariantCulture,
                "loaded (version {0}). {1}",
                Version,
                _settings));
        }

        // ------------------------------------------------------------------
        // runtime
        // ------------------------------------------------------------------

        private RuntimeVersions DetectRuntimeVersions()
        {
            var tshock = TShock.VersionNum;

            return new RuntimeVersions(
                (Version ?? new Version(0, 1, 0)).ToString(3),
                tshock == null
                    ? string.Empty
                    : SupportedRuntimeMatrix.NormalizeTShockVersion(tshock.Major, tshock.Minor, tshock.Build),
                SupportedRuntimeMatrix.NormalizeTerrariaVersion(Main.versionNumber));
        }

        // ------------------------------------------------------------------
        // hooks
        // ------------------------------------------------------------------

        /// <summary>
        /// docs/design.md §7.2: World 初期化完了後に startup Full Snapshot を送る。
        /// </summary>
        private void OnGamePostInitialize(EventArgs args)
        {
            if (!_enabled || _startupSnapshotSent)
            {
                return;
            }

            _startupSnapshotSent = true;

            CaptureAndEnqueue(SnapshotReason.Startup, null);
            _periodic.Arm(DateTime.UtcNow);

            _log.Info("startup snapshot queued for world " + _observer.WorldKey + ".");
        }

        /// <summary>
        /// game thread。Terraria state の読み取りと通知表示はここだけで行い、
        /// HTTP の完了は待たない (docs/design.md §7.6)。
        ///
        /// <para><b>Terraria 1.3.0.8 の制約:</b> <c>Main.DedServ()</c> のループは
        /// <c>if (Netplay.anyClients || ServerApi.ForceUpdate)</c> のときだけ
        /// <c>Update()</c> と <c>GameUpdate</c> hook を回す。誰も接続していない間は
        /// この handler が呼ばれないため、periodic reconciliation・chest change の
        /// 読み直し・通知表示はいずれも保留される。ワールド状態もその間は進まないので
        /// 観測の取りこぼしにはならないが、Backlog 側で後から Mapping を足した場合の
        /// 反映は次にサーバーループが回ったとき（プレイヤー接続時、または
        /// <c>-forceupdate</c> 起動時）になる。</para>
        /// </summary>
        private void OnGameUpdate(EventArgs args)
        {
            if (!_enabled)
            {
                return;
            }

            var now = DateTime.UtcNow;

            if (now < _nextUpdateUtc)
            {
                return;
            }

            _nextUpdateUtc = now + UpdateInterval;

            try
            {
                DrainNotifications();
                PumpManualSync();
                PumpCollectionChange(now);
                PumpPeriodic(now);
            }
            catch (Exception ex)
            {
                _log.Error("game update handler failed: " + ex.Message);
            }
        }

        private void DrainNotifications()
        {
            var batch = new List<AdapterNotification>();
            AdapterNotification notification;

            while (_sender.TryDequeueNotification(out notification))
            {
                batch.Add(notification);
            }

            if (batch.Count == 0)
            {
                return;
            }

            if (_dispatcher.Dispatch(batch))
            {
                // docs/design.md §15.2: 復旧完了通知を表示したらフラグを解除する。
                _sender.OnRecoveryNotificationDisplayed();
            }
        }

        private void PumpCollectionChange(DateTime nowUtc)
        {
            CollectionChangeTrigger trigger;

            if (!_tracker.TryTakeDueChange(nowUtc, _observer.IsCollectionChest, out trigger))
            {
                return;
            }

            if (trigger.UnattributedObservations > 0)
            {
                // 推測で playerNames を埋めない。件数だけを診断に残す。
                _log.Warn(string.Format(
                    CultureInfo.InvariantCulture,
                    "{0} collection chest observation(s) had no attributable player; "
                    + "they are reported without a player name.",
                    trigger.UnattributedObservations));
            }

            CaptureAndEnqueue(SnapshotReason.CollectionChange, trigger.PlayerNames);
        }

        private void PumpManualSync()
        {
            if (!_manualSyncRequested)
            {
                return;
            }

            _manualSyncRequested = false;

            // docs/design.md §7.5: 復旧 ACK をコマンド実行者へは送らない。
            // Snapshot だけを送り、通知は PHP の判断に従う。
            CaptureAndEnqueue(SnapshotReason.Manual, null);
        }

        private void PumpPeriodic(DateTime nowUtc)
        {
            if (!_startupSnapshotSent || !_periodic.TryTakeDue(nowUtc))
            {
                return;
            }

            // docs/design.md §7.4: periodic は ACK recipient を持たない。
            CaptureAndEnqueue(SnapshotReason.Periodic, null);
        }

        /// <summary>
        /// game thread 上で現在状態を読み、送信キューへ積むだけ。
        /// </summary>
        private void CaptureAndEnqueue(string reason, IEnumerable<string> playerNames)
        {
            var observation = _observer.Capture(DateTimeOffset.Now);

            _sender.Enqueue(new PendingSnapshot(reason, observation, playerNames));
        }

        // ------------------------------------------------------------------
        // /backlog sync (docs/design.md §7.5)
        // ------------------------------------------------------------------

        private void OnBacklogCommand(CommandArgs args)
        {
            if (args == null)
            {
                return;
            }

            if (args.Parameters == null || args.Parameters.Count == 0
                || !string.Equals(args.Parameters[0], "sync", StringComparison.OrdinalIgnoreCase))
            {
                args.Player.SendErrorMessage("Usage: /backlog sync");

                return;
            }

            if (!_enabled)
            {
                args.Player.SendErrorMessage(
                    "TerrariaBacklog adapter is disabled (configuration or runtime gate). See the server console.");

                return;
            }

            // Command handler は console 入力スレッドからも呼ばれる。
            // Terraria state の読み取りは game thread に限るため、ここでは要求フラグだけ立て、
            // 実際の capture は OnGameUpdate で行う。
            // Mapping / Registry / Backlog の仕様は C# 側で扱わない。
            _manualSyncRequested = true;

            args.Player.SendSuccessMessage("{0}", "[Backlog] manual snapshot requested.");

            if (!Netplay.anyClients && !ServerApi.ForceUpdate)
            {
                // Terraria 1.3.0.8 の Main.DedServ() は
                // `if (Netplay.anyClients || ServerApi.ForceUpdate)` のときだけ
                // Update() / GameUpdate hook を回す。誰も接続していない間は
                // game thread が進まないため、この要求は保留される。
                args.Player.SendInfoMessage("{0}",
                    "[Backlog] no players are connected; the server update loop is idle. "
                    + "The snapshot will be captured once the loop resumes "
                    + "(a player joins, or the server is started with -forceupdate).");
            }
        }

        // ------------------------------------------------------------------
        // teardown
        // ------------------------------------------------------------------

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                try
                {
                    Teardown();
                }
                catch (Exception ex)
                {
                    _log.Error("teardown failed: " + ex.Message);
                }
            }

            base.Dispose(disposing);
        }

        private void Teardown()
        {
            if (_chestChangeWatcher != null)
            {
                _chestChangeWatcher.Deregister();
                _chestChangeWatcher = null;
            }

            if (_enabled)
            {
                ServerApi.Hooks.GamePostInitialize.Deregister(this, OnGamePostInitialize);
                ServerApi.Hooks.GameUpdate.Deregister(this, OnGameUpdate);
            }

            if (_command != null)
            {
                Commands.ChatCommands.Remove(_command);
                _command = null;
            }

            if (_sender != null)
            {
                // メモリ上の未送信 Snapshot と ACK context はここで失われてよい
                // (docs/design.md §7.6)。永続化しない。
                _sender.Dispose();
                _sender = null;
            }

            _enabled = false;
        }
    }
}
