using TerrariaBacklog.Adapter.Configuration;
using TerrariaBacklog.Adapter.Runtime;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §5.1, §7.1: World Key と Adapter 設定。
/// </summary>
public class WorldKeyTests
{
    [Fact]
    public void DefaultsToTerrariaWorldId()
    {
        Assert.Equal("terraria:123456789", WorldKeyFactory.Create(123456789, null));
    }

    [Theory]
    [InlineData("")]
    [InlineData("   ")]
    public void BlankOverrideFallsBackToTheDefault(string blank)
    {
        Assert.Equal("terraria:42", WorldKeyFactory.Create(42, blank));
    }

    [Fact]
    public void OverrideWins()
    {
        // .wld のコピーを別ワールドとして運用する場合 (docs/design.md §5.1)。
        Assert.Equal(
            "terraria:fusic-multiplayer-2026",
            WorldKeyFactory.Create(123456789, " terraria:fusic-multiplayer-2026 "));
    }

    [Fact]
    public void WorldNameIsNeverPartOfTheKey()
    {
        // docs/specs/world-identity.md: 表示名を識別子に使わない。
        var key = WorldKeyFactory.Create(7, null);

        Assert.DoesNotContain("Fusic", key, StringComparison.OrdinalIgnoreCase);
        Assert.Equal("terraria:7", key);
    }
}

public class AdapterSettingsTests
{
    [Fact]
    public void DefaultsMatchTheDesignDocument()
    {
        var settings = new AdapterSettings();

        Assert.Equal("BACKLOG_COLLECTION", settings.CollectionChestName);
        Assert.Equal(60, settings.ReconciliationIntervalSeconds);
        Assert.Equal(500, settings.ChestChangeDebounceMilliseconds);
        Assert.Null(settings.WorldKeyOverride);
    }

    [Fact]
    public void ValidationFailsWithoutAnAdapterToken()
    {
        var settings = new AdapterSettings();

        var problems = settings.Validate();

        Assert.Contains(problems, p => p.Contains("AdapterToken", StringComparison.Ordinal));
    }

    [Fact]
    public void ValidConfigurationHasNoProblems()
    {
        var settings = new AdapterSettings { AdapterToken = "secret" };

        Assert.Empty(settings.Validate());
    }

    [Theory]
    [InlineData("not-a-url")]
    [InlineData("ftp://example.test")]
    [InlineData("")]
    public void BridgeUrlMustBeAbsoluteHttp(string url)
    {
        var settings = new AdapterSettings { AdapterToken = "secret", BridgeUrl = url };

        Assert.Contains(settings.Validate(), p => p.Contains("BridgeUrl", StringComparison.Ordinal));
    }

    [Fact]
    public void SnapshotUrlMatchesTheBridgeRoute()
    {
        var settings = new AdapterSettings { BridgeUrl = "http://127.0.0.1:8080/" };

        Assert.Equal(
            "http://127.0.0.1:8080/api/v1/worlds/terraria:123456789/snapshots",
            settings.BuildSnapshotUrl("terraria:123456789"));
    }

    [Fact]
    public void WorldKeySegmentKeepsColonButEscapesUnsafeCharacters()
    {
        // Bridge の route 制約は [A-Za-z0-9:._-]。`:` は RFC 3986 の pchar なので escape しない。
        Assert.Equal("terraria:1", AdapterSettings.EncodeWorldKeySegment("terraria:1"));
        Assert.Equal("a%2Fb", AdapterSettings.EncodeWorldKeySegment("a/b"));
        Assert.Equal("a%20b", AdapterSettings.EncodeWorldKeySegment("a b"));
    }

    [Fact]
    public void ConfigurationRoundTripsThroughJson()
    {
        var original = new AdapterSettings
        {
            BridgeUrl = "https://bridge.test",
            AdapterToken = "secret",
            WorldKeyOverride = "terraria:copy",
            CollectionChestName = "DELIVERY_BOX",
            ReconciliationIntervalSeconds = 120,
            ChestChangeDebounceMilliseconds = 750,
            ChestChangeMaxDelayMilliseconds = 4000,
            RequestTimeoutSeconds = 20,
        };

        var restored = AdapterSettings.FromJson(original.ToJson(true), out var error);

        Assert.Null(error);
        Assert.NotNull(restored);
        Assert.Equal("https://bridge.test", restored!.BridgeUrl);
        Assert.Equal("secret", restored.AdapterToken);
        Assert.Equal("terraria:copy", restored.WorldKeyOverride);
        Assert.Equal("DELIVERY_BOX", restored.CollectionChestName);
        Assert.Equal(120, restored.ReconciliationIntervalSeconds);
        Assert.Equal(750, restored.ChestChangeDebounceMilliseconds);
        Assert.Equal(4000, restored.ChestChangeMaxDelayMilliseconds);
        Assert.Equal(20, restored.RequestTimeoutSeconds);
    }

    [Fact]
    public void UnknownConfigurationKeysAreIgnored()
    {
        var restored = AdapterSettings.FromJson(
            "{\"CollectionChestName\":\"CUSTOM\",\"SomethingNew\":123}",
            out var error);

        Assert.Null(error);
        Assert.Equal("CUSTOM", restored!.CollectionChestName);
    }

    [Fact]
    public void TokenIsNeverIncludedInTheDiagnosticString()
    {
        var settings = new AdapterSettings { AdapterToken = "super-secret-token" };

        Assert.DoesNotContain("super-secret-token", settings.ToString(), StringComparison.Ordinal);
    }

    [Fact]
    public void EnvironmentVariableOverridesTheFileToken()
    {
        var settings = new AdapterSettings { AdapterToken = "from-file" };

        Environment.SetEnvironmentVariable(AdapterSettingsFile.TokenEnvironmentVariable, "from-env");

        try
        {
            AdapterSettingsFile.ApplyEnvironmentOverrides(settings);

            Assert.Equal("from-env", settings.AdapterToken);
        }
        finally
        {
            Environment.SetEnvironmentVariable(AdapterSettingsFile.TokenEnvironmentVariable, null);
        }
    }
}
