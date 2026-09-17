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
    /// <summary>
    /// 応答は送信した envelope の requestId / worldKey と一致していなければ
    /// 通知として採用されない（contracts/snapshot-response-v1.schema.json）。
    /// テストでは requestId を固定して、応答 fixture と一致させる。
    /// </summary>
    private const string RequestId = "0199f136-9e36-7f41-b148-e5b4f384a321";

    private const string WorldKey = "terraria:123456789";

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

        return new SnapshotSender(Settings(), Runtime(), transport, log, () => Guid.Parse(RequestId));
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

    // ------------------------------------------------------------------
    // 応答 (envelope) の照合 (contracts/snapshot-response-v1.schema.json)
    // ------------------------------------------------------------------

    private const string OtherRequestId = "0199f200-1111-7222-8333-444455556666";

    private static string OkResponse(string requestId, string worldKey) =>
        $$"""
        {
          "requestId": "{{requestId}}",
          "worldKey": "{{worldKey}}",
          "notifications": [
            { "audience": "players", "playerNames": ["player1"], "message": "[Backlog] 登録しました。" }
          ]
        }
        """;

    [Fact]
    public void ResponseForAnotherRequestIdIsNotDispatched()
    {
        // 古い応答・別要求の応答をそのまま表示すると、別要求の通知が
        // 誤った Player へ出る。requestId が一致しないものは捨てる。
        var transport = FakeTransport.AlwaysOk(OkResponse(OtherRequestId, WorldKey));
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.False(sender.TryDequeueNotification(out _));
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void ResponseForAnotherWorldKeyIsNotDispatched()
    {
        var transport = FakeTransport.AlwaysOk(OkResponse(RequestId, "terraria:999999999"));
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.False(sender.TryDequeueNotification(out _));
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void ResponseWithoutTheRequiredIdentityIsNotDispatched()
    {
        // requestId / worldKey は response contract の required。
        // 欠けた 200 応答は照合できないので通知を採用しない。
        const string missingIdentity =
            """
            {
              "notifications": [
                { "audience": "players", "playerNames": ["player1"], "message": "[Backlog] 登録しました。" }
              ]
            }
            """;

        var transport = FakeTransport.AlwaysOk(missingIdentity);
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.False(sender.TryDequeueNotification(out _));
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void MatchingResponseIsDispatchedRegardlessOfRequestIdCasing()
    {
        // requestId は UUID の16進表記。大文字小文字の差だけで捨てない。
        var transport = FakeTransport.AlwaysOk(OkResponse(RequestId.ToUpperInvariant(), WorldKey));
        var sender = NewSender(transport, out _);

        sender.Enqueue(Collection("player1"));
        sender.PumpOnce();

        Assert.True(sender.TryDequeueNotification(out var notification));
        Assert.Equal("[Backlog] 登録しました。", notification.Message);
    }

    // ------------------------------------------------------------------
    // 復旧待ちフラグ (docs/design.md §15.2 / AC-09)
    // ------------------------------------------------------------------

    [Fact]
    public void UnreadableSuccessResponseAlsoSetsTheRecoveryPendingFlag()
    {
        // 2xx でも body が壊れていれば結果は不明。次の periodic / manual に
        // recoveryPending を載せないと、PHP が復旧後の server 通知を出せない。
        var transport = new FakeTransport((_, _, _) => SnapshotTransportResult.FromResponse(200, "not json"));
        var sender = NewSender(transport, out var log);

        sender.Enqueue(Periodic());
        sender.PumpOnce();

        Assert.True(sender.RecoveryPending);
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void MismatchedResponseAlsoSetsTheRecoveryPendingFlag()
    {
        var transport = FakeTransport.AlwaysOk(OkResponse(OtherRequestId, WorldKey));
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();

        Assert.True(sender.RecoveryPending);
    }

    [Fact]
    public void RecoveryFlagStaysSetWhenANewerRequestFailsBeforeTheNoticeIsDisplayed()
    {
        // worker が復旧通知を積んだ後、game thread が drain する前に後続要求が失敗した場合、
        // 古い通知の表示でフラグを解除してはならない（新しい失敗を隠さない）。
        var responses = new Queue<SnapshotTransportResult>(
        [
            SnapshotTransportResult.FromFailure("down"),
            SnapshotTransportResult.FromResponse(200, OkResponseWithRecoveryNotice),
            SnapshotTransportResult.FromFailure("down again"),
            SnapshotTransportResult.FromResponse(200, OkResponseWithoutNotifications),
        ]);

        var transport = new FakeTransport((_, _, _) => responses.Dequeue());
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();
        Assert.True(sender.RecoveryPending);

        // 復旧通知が queue に積まれる。まだ表示はしていない。
        sender.Enqueue(Periodic());
        sender.PumpOnce();

        // drain 前に後続要求が失敗する。
        sender.Enqueue(Periodic());
        sender.PumpOnce();

        // ここで初めて表示する。積まれた通知は古い世代のものなので解除しない。
        Assert.True(sender.TryDequeueNotification(out var notification));
        Assert.Equal("server", notification.Audience);
        sender.OnNotificationsDispatched(true);

        Assert.True(sender.RecoveryPending);

        // 以後の periodic にも recoveryPending が載り続ける。
        sender.Enqueue(Periodic());
        sender.PumpOnce();
        var bodies = transport.Sent.ToArray();
        Assert.Contains("\"recoveryPending\":true", bodies[3].Body, StringComparison.Ordinal);
    }

    [Fact]
    public void DispatchWithoutAServerNotificationDoesNotClearTheRecoveryFlag()
    {
        var responses = new Queue<SnapshotTransportResult>(
        [
            SnapshotTransportResult.FromFailure("down"),
            SnapshotTransportResult.FromResponse(200, OkResponseWithPlayerAck),
        ]);

        var transport = new FakeTransport((_, _, _) => responses.Dequeue());
        var sender = NewSender(transport, out _);

        sender.Enqueue(Periodic());
        sender.PumpOnce();

        sender.Enqueue(Periodic());
        sender.PumpOnce();

        Assert.True(sender.TryDequeueNotification(out _));
        sender.OnNotificationsDispatched(false);

        Assert.True(sender.RecoveryPending);
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
