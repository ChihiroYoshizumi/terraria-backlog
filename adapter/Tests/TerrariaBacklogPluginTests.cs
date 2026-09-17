using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using System.Reflection;
using System.Runtime.InteropServices;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// 検証対象の TerrariaBacklog.Adapter.dll は net45 (docs/design.md §2.3) であり、
/// net9.0 のテストホストへ直接ロードすることはできない（非 Windows では Mono が必要になる）。
///
/// また TShock の <c>Terraria.Main</c> は実際に稼働している TShock Server 内でのみ安全に
/// 構築できる（静的コンストラクタが実行環境のパス等に依存する）。
///
/// そのため <see cref="MetadataLoadContext"/> でアセンブリの「メタデータだけ」を読み、
/// TShock Plugin としての契約（ApiVersion 属性の値 / TerrariaPlugin 継承 / コンストラクタ形状 /
/// override の有無）を実行ランタイムに依存せず検証する。
/// </summary>
public sealed class AdapterMetadataFixture : IDisposable
{
    public const string TerrariaPluginTypeName = "TerrariaApi.Server.TerrariaPlugin";
    public const string ApiVersionAttributeTypeName = "TerrariaApi.Server.ApiVersionAttribute";
    public const string TerrariaMainTypeName = "Terraria.Main";

    private readonly MetadataLoadContext _context;

    public AdapterMetadataFixture()
    {
        var underTestDir = Path.Combine(AppContext.BaseDirectory, "adapter-under-test");
        var adapterPath = Path.Combine(underTestDir, "TerrariaBacklog.Adapter.dll");

        Assert.True(
            File.Exists(adapterPath),
            $"Adapter assembly under test not found at {adapterPath}. " +
            "Run scripts/setup-tshock.sh and build adapter/TerrariaBacklog.Adapter.csproj first.");

        // net45 アセンブリの typeref (mscorlib 等) は .NET のランタイムディレクトリにある
        // 参照可能なアセンブリ群から解決する。TerrariaServer.exe / TShockAPI.dll は
        // csproj の CopyAdapterUnderTest target が同じディレクトリへ配置している。
        var paths = new List<string>(Directory.GetFiles(RuntimeEnvironment.GetRuntimeDirectory(), "*.dll"));
        paths.AddRange(Directory.GetFiles(underTestDir, "*.dll"));
        paths.AddRange(Directory.GetFiles(underTestDir, "*.exe"));

        _context = new MetadataLoadContext(new PathAssemblyResolver(paths));
        AdapterAssembly = _context.LoadFromAssemblyPath(adapterPath);

        PluginType = Assert.Single(AdapterAssembly.GetTypes(), t => t.Name == "TerrariaBacklogPlugin");
    }

    public Assembly AdapterAssembly { get; }

    public Type PluginType { get; }

    public void Dispose() => _context.Dispose();
}

public class TerrariaBacklogPluginTests : IClassFixture<AdapterMetadataFixture>
{
    private readonly AdapterMetadataFixture _fixture;

    public TerrariaBacklogPluginTests(AdapterMetadataFixture fixture) => _fixture = fixture;

    private Type PluginType => _fixture.PluginType;

    [Fact]
    public void ExtendsTerrariaPlugin()
    {
        Assert.Equal(AdapterMetadataFixture.TerrariaPluginTypeName, PluginType.BaseType?.FullName);
    }

    [Fact]
    public void DeclaresSupportedApiVersion()
    {
        var attribute = PluginType.GetCustomAttributesData()
            .SingleOrDefault(a => a.AttributeType.FullName == AdapterMetadataFixture.ApiVersionAttributeTypeName);

        Assert.NotNull(attribute);

        // TShock 4.3.13 (for Terraria 1.3.0.8) 本体が宣言する ServerApi のバージョン
        // (docs/design.md §2.3 / TShockAPI の [ApiVersion(1, 22)])。
        // 値がずれると TShock 側のロード時互換性チェックに掛かるため、
        // 属性の有無ではなく major/minor の値を固定する。
        var arguments = attribute!.ConstructorArguments;
        Assert.Equal(2, arguments.Count);
        Assert.Equal(1, Assert.IsType<int>(arguments[0].Value));
        Assert.Equal(22, Assert.IsType<int>(arguments[1].Value));
    }

    [Fact]
    public void HasSingleConstructorAcceptingTerrariaMain()
    {
        var constructor = Assert.Single(PluginType.GetConstructors());
        var parameter = Assert.Single(constructor.GetParameters());

        Assert.Equal(AdapterMetadataFixture.TerrariaMainTypeName, parameter.ParameterType.FullName);
    }

    [Fact]
    public void OverridesInitializeAndDispose()
    {
        var initialize = PluginType
            .GetMethods(BindingFlags.Public | BindingFlags.Instance | BindingFlags.DeclaredOnly)
            .SingleOrDefault(m => m.Name == "Initialize" && m.GetParameters().Length == 0);

        Assert.NotNull(initialize);
        Assert.True(initialize!.IsVirtual, "Initialize must override TerrariaPlugin.Initialize.");

        var dispose = PluginType
            .GetMethods(BindingFlags.NonPublic | BindingFlags.Instance | BindingFlags.DeclaredOnly)
            .SingleOrDefault(m =>
                m.Name == "Dispose" &&
                m.GetParameters().Length == 1 &&
                m.GetParameters()[0].ParameterType.FullName == "System.Boolean");

        Assert.NotNull(dispose);
        Assert.True(dispose!.IsVirtual, "Dispose(bool) must override TerrariaPlugin.Dispose(bool).");
    }

    [Fact]
    public void DoesNotExposeAchievementOrBacklogConcepts()
    {
        // docs/design.md §3.1: Adapter は Achievement Key / Backlog Issue Key /
        // Registry / Mapping を知らない。Task 01 完了条件のうち
        // 「Adapter 側に Achievement Key / Backlog Issue Key 用の型や定数を作っていない」を
        // 自動テストとして固定する。
        var forbiddenNameFragments = new[] { "Achievement", "BacklogIssue", "Registry", "Mapping" };

        var offendingTypes = _fixture.AdapterAssembly.GetTypes()
            .Where(t => forbiddenNameFragments.Any(f => t.Name.Contains(f, StringComparison.OrdinalIgnoreCase)))
            .Select(t => t.FullName)
            .ToArray();

        Assert.True(offendingTypes.Length == 0, $"Unexpected domain types leaked into Adapter: {string.Join(", ", offendingTypes)}");
    }
}
