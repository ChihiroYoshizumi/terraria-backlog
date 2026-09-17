using System.Collections.Concurrent;
using TerrariaBacklog.Adapter.Logging;
using TerrariaBacklog.Adapter.Snapshots;
using TerrariaBacklog.Adapter.Transport;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// 純ロジック層のテスト用 fake。
///
/// Adapter の TShock 依存は `*.TShock.cs` に隔離してあり、ここから参照しない。
/// </summary>
public sealed class FakeTransport : ISnapshotTransport
{
    private readonly Func<string, string, string, SnapshotTransportResult> _handler;

    public FakeTransport(Func<string, string, string, SnapshotTransportResult> handler)
    {
        _handler = handler;
    }

    public ConcurrentQueue<SentRequest> Sent { get; } = new();

    public SnapshotTransportResult Send(string url, string bearerToken, string jsonBody)
    {
        Sent.Enqueue(new SentRequest(url, bearerToken, jsonBody));

        return _handler(url, bearerToken, jsonBody);
    }

    public static FakeTransport AlwaysOk(string body) => new((_, _, _) => SnapshotTransportResult.FromResponse(200, body));

    public static FakeTransport AlwaysUnreachable() =>
        new((_, _, _) => SnapshotTransportResult.FromFailure("ConnectFailure: simulated"));

    public static FakeTransport AlwaysStatus(int status) =>
        new((_, _, _) => SnapshotTransportResult.FromResponse(status, "{\"error\":{}}"));
}

public sealed record SentRequest(string Url, string BearerToken, string Body);

public sealed class RecordingSink : INotificationSink
{
    public List<(string Player, string Message)> PlayerMessages { get; } = new();

    public List<string> ConsoleMessages { get; } = new();

    /// <summary>オフライン扱いにする Player 名。</summary>
    public HashSet<string> OfflinePlayers { get; } = new(StringComparer.Ordinal);

    public bool SendToPlayer(string playerName, string message)
    {
        if (OfflinePlayers.Contains(playerName))
        {
            return false;
        }

        PlayerMessages.Add((playerName, message));

        return true;
    }

    public void SendToServerConsole(string message) => ConsoleMessages.Add(message);
}

public sealed class RecordingLog : IAdapterLog
{
    public List<string> Infos { get; } = new();

    public List<string> Warnings { get; } = new();

    public List<string> Errors { get; } = new();

    public void Info(string message) => Infos.Add(message);

    public void Warn(string message) => Warnings.Add(message);

    public void Error(string message) => Errors.Add(message);
}

public static class Observations
{
    public const string ChestName = "BACKLOG_COLLECTION";

    public static WorldObservation Build(
        string worldKey = "terraria:123456789",
        string chestName = ChestName,
        IEnumerable<ObservedItem>? items = null,
        bool hardMode = false,
        DateTimeOffset? observedAt = null)
    {
        var flags = new List<KeyValuePair<string, bool>>
        {
            new("downedBoss1", true),
            new("downedBoss3", false),
            new("hardMode", hardMode),
            new("downedMechBoss1", false),
            new("downedMechBoss2", false),
            new("downedMechBoss3", false),
            new("downedPlantBoss", false),
            new("downedGolemBoss", false),
            new("downedAncientCultist", false),
            new("downedMoonlord", false),
        };

        var chest = new ObservedChest(
            120,
            340,
            chestName,
            items ?? new[] { new ObservedItem(1326, 1, "Rod of Discord") });

        return new WorldObservation(
            worldKey,
            123456789,
            "Fusic World",
            chestName,
            flags,
            new[] { chest },
            observedAt ?? new DateTimeOffset(2026, 9, 16, 10, 0, 0, TimeSpan.FromHours(9)));
    }
}
