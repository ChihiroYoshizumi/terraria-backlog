using System;
using System.Reflection;
using Terraria;
using TerrariaApi.Server;
using TShockAPI;

namespace TerrariaBacklog.Adapter;

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
[ApiVersion(2, 1)]
public sealed class TerrariaBacklogPlugin : TerrariaPlugin
{
    public override Version Version =>
        Assembly.GetExecutingAssembly().GetName().Version ?? new Version(0, 1, 0);

    public override string Name => "TerrariaBacklog.Adapter";

    public override string Author => "terraria-backlog";

    public override string Description =>
        "Terraria Backlog Bridge 向けの薄い Adapter。World/Chest 状態の観測結果を PHP へ送信し、" +
        "PHP が確定した通知を表示するだけの Plugin。Achievement / Backlog のドメインロジックは持たない。";

    public TerrariaBacklogPlugin(Main game)
        : base(game)
    {
        // TShock の Plugin 読み込み順に影響しない既定値のままとする。
        Order = 0;
    }

    public override void Initialize()
    {
        TShock.Log.ConsoleInfo($"[TerrariaBacklog.Adapter] loaded (version {Version}). No gameplay hooks registered yet.");
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
