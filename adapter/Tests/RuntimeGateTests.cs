using TerrariaBacklog.Adapter.Runtime;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §2.3 の runtime compatibility gate。
/// 対応外の組み合わせでは hook 登録・Snapshot 送信を開始しない (fail closed)。
/// </summary>
public class RuntimeGateTests
{
    [Fact]
    public void SupportedPairPassesTheGate()
    {
        var supported = SupportedRuntimeMatrix.IsSupported("1.3.0.8", "4.3.13", out var problems);

        Assert.True(supported);
        Assert.Empty(problems);
    }

    [Theory]
    [InlineData("1.4.5.6", "4.3.13")]
    [InlineData("1.3.0.8", "6.1.0")]
    [InlineData("1.3.0.7", "4.3.12")]
    [InlineData("", "")]
    [InlineData(null, null)]
    public void UnsupportedPairFailsTheGate(string? terraria, string? tshock)
    {
        var supported = SupportedRuntimeMatrix.IsSupported(terraria!, tshock!, out var problems);

        Assert.False(supported);
        Assert.NotEmpty(problems);
    }

    [Theory]
    [InlineData("v1.3.0.8", "1.3.0.8")]
    [InlineData("V1.3.0.8", "1.3.0.8")]
    [InlineData(" v1.3.0.8 ", "1.3.0.8")]
    [InlineData("1.3.0.8", "1.3.0.8")]
    [InlineData(null, "")]
    public void TerrariaVersionIsNormalized(string? raw, string expected)
    {
        // Terraria.Main.versionNumber は "v1.3.0.8" 形式 (TerrariaServer.exe の静的初期化子)。
        Assert.Equal(expected, SupportedRuntimeMatrix.NormalizeTerrariaVersion(raw!));
    }

    [Fact]
    public void TShockVersionIsNormalizedToThreeComponents()
    {
        // TShockAPI.dll の AssemblyVersion は 4.3.13.0。
        Assert.Equal("4.3.13", SupportedRuntimeMatrix.NormalizeTShockVersion(4, 3, 13));
        Assert.Equal("4.3.0", SupportedRuntimeMatrix.NormalizeTShockVersion(4, 3, -1));
    }

    [Fact]
    public void NormalizedRuntimeOfTheSupportedServerPassesTheGate()
    {
        var terraria = SupportedRuntimeMatrix.NormalizeTerrariaVersion("v1.3.0.8");
        var tshock = SupportedRuntimeMatrix.NormalizeTShockVersion(4, 3, 13);

        Assert.True(SupportedRuntimeMatrix.IsSupported(terraria, tshock, out _));
    }
}
