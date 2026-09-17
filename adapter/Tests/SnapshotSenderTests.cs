using TerrariaBacklog.Adapter.Configuration;
using TerrariaBacklog.Adapter.Snapshots;
using TerrariaBacklog.Adapter.Transport;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §7.6, §12, §15.2 / AC-09, AC-18。
///
/// single-flight、ACK context の寿命、復旧待ちフラグを検証する。
/// </summary>
public class SnapshotSenderTests
{
    private const string OkResponseWithPlayerAck =
        """
        {
          "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
          "worldKey": "terraria:123456789",
          "notifications": [
            { "audience": "players", "playerNames": ["player1"], "message": "[Backlog] 登録しました。" }
          ]
        }
        """;

    private const string OkResponseWithRecoveryNotice =
        """
        {
          "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
          "worldKey": "terraria:123456789",
          "notifications": [
            { "audience": "server", "message": "[Backlog] 復旧後の再同期が完了しました。" }
          ]
        }
        """;

    private const string OkResponseWithoutNotifications =
        """
        { "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321", "worldKey": "terraria:123456789", "notifications": [] }
        """;

    private static AdapterSettings Settings() => new()
    {
        BridgeUrl = "http://127.0.0.1:8080",
        AdapterToken = "secret-token",
    };

    private static RuntimeVersions Runtime() => new("0.1.0", "4.3.13", "1.3.0.8");

    private static SnapshotSender NewSender(ISnapshotTransport transport, out RecordingLog log)
    {
        log = new RecordingLog();

        return new SnapshotSender(Settings(), Runtime(), transport, log);
    }

    private static PendingSnapshot Collection(params string[] players)
        => new(SnapshotReason.CollectionChange, Observations.Build(), players);

    private static PendingSnapshot Periodic()
        => new(SnapshotReason.Periodic, Observations.Build(), null);

    [Fact]
    public void PumpSendsToTheBridgeRouteWithTheBearerToken()
    {
        var transport = FakeTransport.AlwaysOk(OkResponseWithoutNotifications);
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());

        Assert.True(sender.PumpOnce());
        Assert.True(transport.Sent.TryDequeue(out var request));
        Assert.Equal("http://127.0.0.1:8080/api/v1/worlds/terraria:123456789/snapshots", request!.Url);
        Assert.Equal("secret-token", request.BearerToken);
        Assert.Contains("\"reason\":\"periodic\"", request.Body, StringComparison.Ordinal);
    }

    [Fact]
    public void PumpDoesNothingWhenTheQueueIsEmpty()
    {
        var transport = FakeTransport.AlwaysOk(OkResponseWithoutNotifications);
        var sender = NewSender(transport, out _);

        Assert.False(sender.PumpOnce());
        Assert.Empty(transport.Sent);
    }

    [Fact]
    public async Task OnlyOneRequestRunsAtATime()
    {
        using var inFlight = new ManualResetEventSlim(false);
        using var release = new ManualResetEventSlim(false);
        var concurrent = 0;
        var observedMax = 0;

        var transport = new FakeTransport((_, _, _) =>
        {
            var now = Interlocked.Increment(ref concurrent);
            InterlockedMax(ref observedMax, now);
            inFlight.Set();
            release.Wait(TimeSpan.FromSeconds(5));
            Interlocked.Decrement(ref concurrent);

            return SnapshotTransportResult.FromResponse(200, OkResponseWithoutNotifications);
        });

        var sender = NewSender(transport, out _);

        sender.Enqueue(Collection("player1"));
        sender.Enqueue(Periodic());

        var first = Task.Run(() => sender.PumpOnce());

        Assert.True(inFlight.Wait(TimeSpan.FromSeconds(5)));

        // 1本目が in-flight の間、2本目の Pump は送信を始めない。
        Assert.False(sender.PumpOnce());
        Assert.Single(transport.Sent);

        release.Set();
        Assert.True(await first);
        Assert.Equal(1, observedMax);

        // in-flight が終われば残りが送れる。
        Assert.True(sender.PumpOnce());
        Assert.Equal(2, transport.Sent.Count);
    }

    [Fact]
    public void SuccessfulResponseQueuesTheNotificationsVerbatim()
    {
        var transport = FakeTransport.AlwaysOk(OkResponseWithPlayerAck);
        var sender = NewSender(transport, out _);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.True(sender.TryDequeueNotification(out var notification));
        Assert.Equal("players", notification.Audience);
        Assert.Equal(new[] { "player1" }, notification.PlayerNames);
        Assert.Equal("[Backlog] 登録しました。", notification.Message);
        Assert.False(sender.TryDequeueNotification(out _));
    }

    [Fact]
    public void CollectionChangeCarriesTriggerPlayerNames()
    {
        var transport = FakeTransport.AlwaysOk(OkResponseWithoutNotifications);
        var sender = NewSender(transport, out _);

        sender.Enqueue(Collection("player1", "player2"));
        sender.PumpOnce();

        Assert.True(transport.Sent.TryDequeue(out var request));
        Assert.Contains("\"trigger\":{\"playerNames\":[\"player1\",\"player2\"]}", request!.Body, StringComparison.Ordinal);
    }

    [Fact]
    public void PeriodicSnapshotHasNoAckRecipient()
    {
        var transport = FakeTransport.AlwaysOk(OkResponseWithoutNotifications);
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();

        Assert.True(transport.Sent.TryDequeue(out var request));
        Assert.DoesNotContain("\"trigger\"", request!.Body, StringComparison.Ordinal);
    }

    [Fact]
    public void TransportFailureSetsTheRecoveryPendingFlagAndDropsThePlayerContext()
    {
        var transport = FakeTransport.AlwaysUnreachable();
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        Assert.True(sender.PumpOnce());

        Assert.True(sender.RecoveryPending);
        Assert.NotEmpty(log.Errors);

        // 失敗した要求の Player context は保持しない。再送もしない。
        Assert.Equal(0, sender.PendingCount);
        Assert.False(sender.PumpOnce());
        Assert.Single(transport.Sent);

        // 後続 periodic に過去の Player が紛れ込まない。
        sender.Enqueue(Periodic());
        sender.PumpOnce();

        Assert.Equal(2, transport.Sent.Count);
        var bodies = transport.Sent.ToArray();
        Assert.DoesNotContain("player1", bodies[1].Body, StringComparison.Ordinal);
        Assert.Contains("\"recoveryPending\":true", bodies[1].Body, StringComparison.Ordinal);
    }

    [Fact]
    public void NonSuccessResponseAlsoSetsTheRecoveryPendingFlag()
    {
        var transport = FakeTransport.AlwaysStatus(503);
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.True(sender.RecoveryPending);
        // Adapter 自身が成功 ACK を作らない。
        Assert.False(sender.TryDequeueNotification(out _));
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void RecoveryPendingIsOnlyAttachedToPeriodicAndManualSnapshots()
    {
        var transport = new FakeTransport((_, _, _) => SnapshotTransportResult.FromFailure("down"));
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();
        Assert.True(sender.RecoveryPending);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        var bodies = transport.Sent.ToArray();
        Assert.DoesNotContain("recoveryPending", bodies[1].Body, StringComparison.Ordinal);

        sender.Enqueue(new PendingSnapshot(SnapshotReason.Manual, Observations.Build(), null));
        sender.PumpOnce();

        bodies = transport.Sent.ToArray();
        Assert.Contains("\"recoveryPending\":true", bodies[2].Body, StringComparison.Ordinal);
    }

    [Fact]
    public void RecoveryFlagIsClearedOnlyAfterTheServerNotificationIsDisplayed()
    {
        var responses = new Queue<SnapshotTransportResult>(
        [
            SnapshotTransportResult.FromFailure("down"),
            SnapshotTransportResult.FromResponse(200, OkResponseWithoutNotifications),
            SnapshotTransportResult.FromResponse(200, OkResponseWithRecoveryNotice),
            SnapshotTransportResult.FromResponse(200, OkResponseWithoutNotifications),
        ]);

        var transport = new FakeTransport((_, _, _) => responses.Dequeue());
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();
        Assert.True(sender.RecoveryPending);

        // 復旧完了通知が返らない周期ではフラグを解除しない。
        sender.Enqueue(Periodic());
        sender.PumpOnce();
        Assert.False(sender.TryDequeueNotification(out _));
        Assert.True(sender.RecoveryPending);

        // 復旧完了通知を受け取って表示したら解除する。
        sender.Enqueue(Periodic());
        sender.PumpOnce();
        Assert.True(sender.TryDequeueNotification(out var notification));
        Assert.Equal("server", notification.Audience);
        sender.OnRecoveryNotificationDisplayed();
        Assert.False(sender.RecoveryPending);

        // 以後の正常周期で繰り返し通知しない。
        sender.Enqueue(Periodic());
        sender.PumpOnce();
        var bodies = transport.Sent.ToArray();
        Assert.DoesNotContain("recoveryPending", bodies[3].Body, StringComparison.Ordinal);
    }

    [Fact]
    public void AckContextAndRecoveryFlagDoNotSurviveAProcessRestart()
    {
        var transport = FakeTransport.AlwaysUnreachable();
        var sender = NewSender(transport, out _);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();
        Assert.True(sender.RecoveryPending);

        // 新しい process = 新しい instance。永続 Queue / Outbox を持たないため
        // 未送信 Snapshot も ACK context も復旧待ちフラグも復元されない。
        var restarted = new SnapshotSender(Settings(), Runtime(), transport, new RecordingLog());

        Assert.False(restarted.RecoveryPending);
        Assert.Equal(0, restarted.PendingCount);
        Assert.False(restarted.TryDequeueNotification(out _));
        Assert.False(restarted.PumpOnce());
    }

    [Fact]
    public void UnreadableSuccessResponseNeverFabricatesAnAck()
    {
        var transport = new FakeTransport((_, _, _) => SnapshotTransportResult.FromResponse(200, "not json"));
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.False(sender.TryDequeueNotification(out _));
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void TransportExceptionsDoNotEscapeToTheGameLoop()
    {
        var transport = new FakeTransport((_, _, _) => throw new InvalidOperationException("boom"));
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Periodic());

        var exception = Record.Exception(() => sender.PumpOnce());

        Assert.Null(exception);
        Assert.True(sender.RecoveryPending);
        Assert.NotEmpty(log.Errors);
    }

    private static void InterlockedMax(ref int target, int value)
    {
        int current;

        while ((current = Volatile.Read(ref target)) < value)
        {
            if (Interlocked.CompareExchange(ref target, value, current) == current)
            {
                return;
            }
        }
    }
}
