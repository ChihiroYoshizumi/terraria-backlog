using System;
using System.Linq;
using System.Reflection;
using Terraria;
using TerrariaApi.Server;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// Task 01 時点での TerrariaBacklogPlugin は no-op であり、gameplay hook 登録もない。
///
/// TShock の <c>Terraria.Main</c> は実際に稼働している TShock Server 内でのみ安全に
/// 構築できる（静的コンストラクタが実行環境のパス等に依存するため、独立した unit test
/// プロセス内で `new Main()` すると例外になる）。そのためここではリフレクションで
/// TShock Plugin としての契約（ApiVersion 属性 / TerrariaPlugin 継承 / コンストラクタ形状）
/// のみを検証し、実インスタンス化は行わない。
/// </summary>
public class TerrariaBacklogPluginTests
{
    private static readonly Type PluginType = typeof(TerrariaBacklogPlugin);

    [Fact]
    public void ExtendsTerrariaPlugin()
    {
        Assert.Equal(typeof(TerrariaPlugin), PluginType.BaseType);
    }

    [Fact]
    public void DeclaresSupportedApiVersion()
    {
        var attribute = PluginType.GetCustomAttribute<ApiVersionAttribute>();

        Assert.NotNull(attribute);
    }

    [Fact]
    public void HasSingleConstructorAcceptingTerrariaMain()
    {
        var constructors = PluginType.GetConstructors();
        var constructor = Assert.Single(constructors);
        var parameters = constructor.GetParameters();

        var parameter = Assert.Single(parameters);
        Assert.Equal(typeof(Main), parameter.ParameterType);
    }

    [Fact]
    public void OverridesInitializeAndDispose()
    {
        var initialize = PluginType.GetMethod(nameof(TerrariaPlugin.Initialize), BindingFlags.Public | BindingFlags.Instance);
        Assert.NotNull(initialize);
        Assert.True(initialize!.DeclaringType == PluginType);

        var dispose = PluginType.GetMethod("Dispose", BindingFlags.NonPublic | BindingFlags.Instance, new[] { typeof(bool) });
        Assert.NotNull(dispose);
        Assert.True(dispose!.DeclaringType == PluginType);
    }

    [Fact]
    public void DoesNotExposeAchievementOrBacklogConcepts()
    {
        // docs/design.md §3.1: Adapter は Achievement Key / Backlog Issue Key /
        // Registry / Mapping を知らない。Task 01 完了条件のうち
        // 「Adapter 側に Achievement Key / Backlog Issue Key 用の型や定数を作っていない」を
        // 自動テストとして固定する。
        var assembly = PluginType.Assembly;
        var forbiddenNameFragments = new[] { "Achievement", "BacklogIssue", "Registry", "Mapping" };

        var offendingTypes = assembly.GetTypes()
            .Where(t => forbiddenNameFragments.Any(f => t.Name.Contains(f, StringComparison.OrdinalIgnoreCase)))
            .Select(t => t.FullName)
            .ToArray();

        Assert.True(offendingTypes.Length == 0, $"Unexpected domain types leaked into Adapter: {string.Join(", ", offendingTypes)}");
    }
}
