using System.Diagnostics;
using System.Text;
using System.Text.Json;
using TerrariaBacklog.Adapter.Snapshots;
using Xunit;
using Xunit.Abstractions;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// contracts/snapshot-v1.schema.json / docs/design.md §6.2 への適合。
/// </summary>
public class SnapshotEnvelopeTests
{
    private readonly ITestOutputHelper _output;

    public SnapshotEnvelopeTests(ITestOutputHelper output) => _output = output;

    private static readonly Guid RequestId = Guid.Parse("0199f136-9e36-7f41-b148-e5b4f384a321");

    private static SnapshotEnvelope Build(
        string reason = SnapshotReason.CollectionChange,
        IEnumerable<string>? players = null,
        bool recoveryPending = false)
        => new(
            RequestId,
            reason,
            new RuntimeVersions("0.1.0", "4.3.13", "1.3.0.8"),
            Observations.Build(),
            players ?? new[] { "player1" },
            recoveryPending);

    [Fact]
    public void ProducesTheRequiredEnvelopeFields()
    {
        using var document = JsonDocument.Parse(Build().ToJson());
        var root = document.RootElement;

        Assert.Equal(1, root.GetProperty("schemaVersion").GetInt32());
        Assert.Equal(RequestId, root.GetProperty("requestId").GetGuid());
        Assert.Equal("collection_change", root.GetProperty("reason").GetString());
        Assert.Equal(JsonValueKind.String, root.GetProperty("observedAt").ValueKind);
        Assert.Equal("BACKLOG_COLLECTION", root.GetProperty("collectionChestName").GetString());

        var runtime = root.GetProperty("runtime");
        Assert.Equal("0.1.0", runtime.GetProperty("adapterVersion").GetString());
        Assert.Equal("4.3.13", runtime.GetProperty("tshockVersion").GetString());
        Assert.Equal("1.3.0.8", runtime.GetProperty("terrariaVersion").GetString());

        var world = root.GetProperty("world");
        Assert.Equal("terraria:123456789", world.GetProperty("key").GetString());
        Assert.Equal(123456789, world.GetProperty("terrariaWorldId").GetInt32());

        var flags = root.GetProperty("flags");
        Assert.Equal(JsonValueKind.True, flags.GetProperty("downedBoss1").ValueKind);
        Assert.Equal(JsonValueKind.False, flags.GetProperty("hardMode").ValueKind);

        var chest = Assert.Single(root.GetProperty("collectionChests").EnumerateArray().ToArray());
        Assert.Equal(120, chest.GetProperty("x").GetInt32());
        Assert.Equal(340, chest.GetProperty("y").GetInt32());
        Assert.Equal("BACKLOG_COLLECTION", chest.GetProperty("name").GetString());

        var item = Assert.Single(chest.GetProperty("items").EnumerateArray().ToArray());
        Assert.Equal(1326, item.GetProperty("type").GetInt32());
        Assert.Equal(1, item.GetProperty("stack").GetInt32());

        Assert.Equal(["player1"], root.GetProperty("trigger").GetProperty("playerNames")
            .EnumerateArray().Select(e => e.GetString() ?? string.Empty).ToArray());
    }

    [Fact]
    public void ObservedAtCarriesAUtcOffset()
    {
        // Bridge の SnapshotRequestParser は offset 付き ISO 8601 だけを受理する。
        var json = Build().ToJson();

        using var document = JsonDocument.Parse(json);
        var observedAt = document.RootElement.GetProperty("observedAt").GetString();

        Assert.Equal("2026-09-16T10:00:00.000+09:00", observedAt);
        Assert.Matches(
            @"^\d{4}-\d{2}-\d{2}[Tt]\d{2}:\d{2}:\d{2}(\.\d+)?([Zz]|[+-]\d{2}:\d{2})$",
            observedAt!);
    }

    [Fact]
    public void RecoveryPendingIsOmittedWhenFalse()
    {
        using var document = JsonDocument.Parse(Build(SnapshotReason.Periodic, []).ToJson());

        Assert.False(document.RootElement.TryGetProperty("recoveryPending", out _));
    }

    [Fact]
    public void RecoveryPendingIsAJsonBooleanWhenPresent()
    {
        using var document = JsonDocument.Parse(
            Build(SnapshotReason.Periodic, [], recoveryPending: true).ToJson());

        Assert.Equal(JsonValueKind.True, document.RootElement.GetProperty("recoveryPending").ValueKind);
    }

    [Fact]
    public void TriggerIsOmittedWhenNoPlayerCouldBeAttributed()
    {
        // 推測で playerNames を埋めない (tasks/08 §6.3)。
        using var document = JsonDocument.Parse(Build(SnapshotReason.CollectionChange, []).ToJson());

        Assert.False(document.RootElement.TryGetProperty("trigger", out _));
    }

    [Fact]
    public void ContainsNoAchievementOrBacklogConcepts()
    {
        var json = Build().ToJson();

        foreach (var forbidden in new[] { "achievement", "registry", "mapping", "issueKey", "projectKey", "apiKey" })
        {
            Assert.DoesNotContain(forbidden, json, StringComparison.OrdinalIgnoreCase);
        }
    }

    [Fact]
    public void RejectsAnUnsupportedReason()
    {
        Assert.Throws<ArgumentException>(() => new SnapshotEnvelope(
            RequestId,
            "boss_defeated",
            new RuntimeVersions("0.1.0", "4.3.13", "1.3.0.8"),
            Observations.Build(),
            null,
            false));
    }

    [Theory]
    [InlineData(SnapshotReason.Startup)]
    [InlineData(SnapshotReason.Periodic)]
    [InlineData(SnapshotReason.CollectionChange)]
    [InlineData(SnapshotReason.WorldChange)]
    [InlineData(SnapshotReason.Manual)]
    public void AllContractReasonsAreAccepted(string reason)
    {
        using var document = JsonDocument.Parse(Build(reason).ToJson());

        Assert.Equal(reason, document.RootElement.GetProperty("reason").GetString());
    }

    [Fact]
    public void EscapesStringsSafely()
    {
        var observation = new WorldObservation(
            "terraria:1",
            1,
            "quote\" backslash\\ newline\n",
            "CHEST\"NAME",
            [new KeyValuePair<string, bool>("hardMode", true)],
            [new ObservedChest(1, 2, "CHEST\"NAME", [new ObservedItem(1, 1, "tab\there")])],
            DateTimeOffset.UnixEpoch);

        var envelope = new SnapshotEnvelope(
            RequestId,
            SnapshotReason.Startup,
            new RuntimeVersions("0.1.0", "4.3.13", "1.3.0.8"),
            observation,
            null,
            false);

        using var document = JsonDocument.Parse(envelope.ToJson());

        Assert.Equal("quote\" backslash\\ newline\n", document.RootElement.GetProperty("world").GetProperty("name").GetString());
        Assert.Equal("CHEST\"NAME", document.RootElement.GetProperty("collectionChestName").GetString());
    }

    // ------------------------------------------------------------------
    // contracts/ の JSON Schema に対する実検証 (ajv)
    // ------------------------------------------------------------------

    /// <summary>
    /// Adapter が実際に送る JSON を contracts/snapshot-v1.schema.json へ通す。
    ///
    /// node と contracts/node_modules が用意されている環境でのみ実行する
    /// (`cd contracts &amp;&amp; npm install`)。無い環境では検証をスキップし、
    /// 上の構造アサーションだけで担保する。
    /// </summary>
    [Fact]
    public void GeneratedSnapshotValidatesAgainstTheContractSchema()
    {
        var repositoryRoot = FindRepositoryRoot();

        if (repositoryRoot == null)
        {
            _output.WriteLine("repository root not found; skipping the ajv contract check.");

            return;
        }

        var ajvDirectory = Path.Combine(repositoryRoot, "contracts", "node_modules", "ajv");

        if (!Directory.Exists(ajvDirectory))
        {
            _output.WriteLine("contracts/node_modules/ajv is missing; run `cd contracts && npm install`. Skipping.");

            return;
        }

        var workDirectory = Path.Combine(Path.GetTempPath(), "terraria-backlog-adapter-" + Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(workDirectory);

        try
        {
            var fixtures = new Dictionary<string, string>
            {
                ["collection_change"] = Build().ToJson(),
                ["startup"] = Build(SnapshotReason.Startup, []).ToJson(),
                ["periodic-recovery"] = Build(SnapshotReason.Periodic, [], recoveryPending: true).ToJson(),
                ["manual"] = Build(SnapshotReason.Manual, []).ToJson(),
            };

            var scriptPath = Path.Combine(workDirectory, "validate-fixture.js");
            File.WriteAllText(scriptPath, ValidatorScript, new UTF8Encoding(false));

            foreach (var fixture in fixtures)
            {
                var fixturePath = Path.Combine(workDirectory, fixture.Key + ".json");
                File.WriteAllText(fixturePath, fixture.Value, new UTF8Encoding(false));

                var (exitCode, output) = RunNode(
                    repositoryRoot,
                    scriptPath,
                    Path.Combine(repositoryRoot, "contracts"),
                    fixturePath);

                if (exitCode == 127)
                {
                    _output.WriteLine("node is not available; skipping the ajv contract check.");

                    return;
                }

                _output.WriteLine($"{fixture.Key}: {output}");
                Assert.True(exitCode == 0, $"{fixture.Key} does not conform to snapshot-v1.schema.json:\n{output}");
            }
        }
        finally
        {
            try
            {
                Directory.Delete(workDirectory, recursive: true);
            }
            catch (IOException)
            {
                // 一時ディレクトリの後始末に失敗してもテスト結果には影響させない。
            }
        }
    }

    private const string ValidatorScript = """
        "use strict";
        const fs = require("fs");
        const path = require("path");

        const contractsDir = process.argv[2];
        const fixturePath = process.argv[3];

        const Ajv = require(path.join(contractsDir, "node_modules", "ajv"));
        const addFormats = require(path.join(contractsDir, "node_modules", "ajv-formats"));

        const ajv = new Ajv({ allErrors: true, strict: false });
        addFormats(ajv);

        const schema = JSON.parse(fs.readFileSync(path.join(contractsDir, "snapshot-v1.schema.json"), "utf8"));
        const data = JSON.parse(fs.readFileSync(fixturePath, "utf8"));

        const validate = ajv.compile(schema);

        if (validate(data)) {
          console.log("OK");
          process.exit(0);
        }

        console.error(JSON.stringify(validate.errors, null, 2));
        process.exit(1);
        """;

    private static (int ExitCode, string Output) RunNode(
        string workingDirectory,
        string scriptPath,
        string contractsDirectory,
        string fixturePath)
    {
        var startInfo = new ProcessStartInfo("node")
        {
            WorkingDirectory = workingDirectory,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            UseShellExecute = false,
        };

        startInfo.ArgumentList.Add(scriptPath);
        startInfo.ArgumentList.Add(contractsDirectory);
        startInfo.ArgumentList.Add(fixturePath);

        try
        {
            using var process = Process.Start(startInfo);

            if (process == null)
            {
                return (127, "node could not be started.");
            }

            var stdout = process.StandardOutput.ReadToEnd();
            var stderr = process.StandardError.ReadToEnd();
            process.WaitForExit(60_000);

            return (process.ExitCode, stdout + stderr);
        }
        catch (System.ComponentModel.Win32Exception)
        {
            return (127, "node is not installed.");
        }
    }

    private static string? FindRepositoryRoot()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);

        while (directory != null)
        {
            if (File.Exists(Path.Combine(directory.FullName, "contracts", "snapshot-v1.schema.json")))
            {
                return directory.FullName;
            }

            directory = directory.Parent;
        }

        return null;
    }
}
