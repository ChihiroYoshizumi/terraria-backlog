using System;
using System.Reflection;
using Terraria;
using TerrariaApi.Server;
using TShockAPI;

namespace TerrariaBacklog.Adapter
{
    /// <summary>
    /// TShock が ServerPlugins からロードする TerrariaBacklog Adapter のエントリポイント。
    ///
    /// Task 01 (プロジェクト土台) の時点では no-op とし、ロード完了をコンソールへ
    /// 出力するだけに留める。gameplay hook の登録・World/Chest Snapshot の生成・
    /// PHP Bridge への HTTP 送信・Achievement 判定は後続 Task (08: tshock-adapter) で実装する。
    ///
    /// 責務境界 (docs/design.md §3.1) に従い、この Plugin は Achievement Key や
    /// Backlog Registry / Mapping / Issue Key を一切知らない。
    /// </summary>
    /// <remarks>
    /// ApiVersion は TShock 4.3.13 (for Terraria 1.3.0.8) 本体が宣言する ServerApi の
    /// バージョン 1.22 に合わせる (docs/design.md §2.3)。値がずれると TShock の
    /// Plugin ロード時互換性チェックで弾かれる。
    /// </remarks>
    [ApiVersion(1, 22)]
    public sealed class TerrariaBacklogPlugin : TerrariaPlugin
    {
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
            // TShock の Plugin 読み込み順に影響しない既定値のままとする。
            Order = 0;
        }

        public override void Initialize()
        {
            TShock.Log.ConsoleInfo(
                string.Format(
                    "[TerrariaBacklog.Adapter] loaded (version {0}). No gameplay hooks registered yet.",
                    Version));
        }

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                // Task 01 時点では登録した hook / managed resource がないため何もしない。
            }

            base.Dispose(disposing);
        }
    }
}
