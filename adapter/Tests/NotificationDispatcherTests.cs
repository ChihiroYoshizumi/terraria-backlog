using TerrariaBacklog.Adapter.Transport;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §6.5, §15.1, §15.2。
///
/// Adapter は PHP の通知命令をそのまま表示するだけで、message や宛先を再解釈しない。
/// </summary>
public class NotificationDispatcherTests
{
    private static (NotificationDispatcher Dispatcher, RecordingSink Sink, RecordingLog Log) New()
    {
        var sink = new RecordingSink();
        var log = new RecordingLog();

        return (new NotificationDispatcher(sink, log), sink, log);
    }

    [Fact]
    public void PlayersAudienceGoesToTheNamedPlayersOnly()
    {
        var (dispatcher, sink, _) = New();

        var displayedServerNotice = dispatcher.Dispatch(
        [
            new AdapterNotification("players", ["player1", "player2"], "[Backlog] 登録しました。"),
        ]);

        Assert.False(displayedServerNotice);
        Assert.Equal(
            [("player1", "[Backlog] 登録しました。"), ("player2", "[Backlog] 登録しました。")],
            sink.PlayerMessages);
        Assert.Empty(sink.ConsoleMessages);
    }

    [Fact]
    public void ServerAudienceGoesToTheConsoleOnly()
    {
        var (dispatcher, sink, _) = New();

        var displayedServerNotice = dispatcher.Dispatch(
        [
            new AdapterNotification("server", null, "[Backlog] 復旧後の再同期が完了しました。"),
        ]);

        Assert.True(displayedServerNotice);
        Assert.Equal(["[Backlog] 復旧後の再同期が完了しました。"], sink.ConsoleMessages);

        // ゲーム内の Player / 全体チャットへは出さない。
        Assert.Empty(sink.PlayerMessages);
    }

    [Fact]
    public void ServerAudienceNeverReachesPlayersEvenIfPlayerNamesArePresent()
    {
        var (dispatcher, sink, _) = New();

        dispatcher.Dispatch([new AdapterNotification("server", ["player1"], "console only")]);

        Assert.Single(sink.ConsoleMessages);
        Assert.Empty(sink.PlayerMessages);
    }

    [Fact]
    public void MessageIsDisplayedVerbatim()
    {
        var (dispatcher, sink, _) = New();

        const string message = "[Backlog] Rod of Discord を登録しました。取り出してOKです。";

        dispatcher.Dispatch([new AdapterNotification("players", ["player1"], message)]);

        Assert.Equal(message, sink.PlayerMessages.Single().Message);
    }

    [Fact]
    public void PlayersAudienceWithoutRecipientsIsDroppedNotBroadcast()
    {
        var (dispatcher, sink, log) = New();

        dispatcher.Dispatch([new AdapterNotification("players", [], "no recipient")]);

        Assert.Empty(sink.PlayerMessages);
        Assert.Empty(sink.ConsoleMessages);
        Assert.NotEmpty(log.Warnings);
    }

    [Fact]
    public void UnknownAudienceIsDroppedNotGuessed()
    {
        var (dispatcher, sink, log) = New();

        dispatcher.Dispatch([new AdapterNotification("everyone", ["player1"], "?")]);

        Assert.Empty(sink.PlayerMessages);
        Assert.Empty(sink.ConsoleMessages);
        Assert.NotEmpty(log.Warnings);
    }

    [Fact]
    public void OfflineRecipientIsLoggedAndSkipped()
    {
        var sink = new RecordingSink();
        sink.OfflinePlayers.Add("player2");
        var log = new RecordingLog();
        var dispatcher = new NotificationDispatcher(sink, log);

        dispatcher.Dispatch([new AdapterNotification("players", ["player1", "player2"], "hi")]);

        Assert.Equal([("player1", "hi")], sink.PlayerMessages);
        Assert.NotEmpty(log.Infos);
    }
}

/// <summary>
/// contracts/snapshot-response-v1.schema.json の読み取り。
/// </summary>
public class SnapshotResponseReaderTests
{
    [Fact]
    public void ReadsTheContractExample()
    {
        const string json =
            """
            {
              "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
              "worldKey": "terraria:123456789",
              "notifications": [
                {
                  "audience": "players",
                  "playerNames": ["player1"],
                  "message": "[Backlog] Rod of Discord を登録しました。取り出してOKです。"
                }
              ]
            }
            """;

        Assert.True(SnapshotResponseReader.TryRead(json, out var response, out var error));
        Assert.Null(error);
        Assert.Equal("terraria:123456789", response.WorldKey);

        var notification = Assert.Single(response.Notifications);
        Assert.True(notification.IsForPlayers);
        Assert.Equal(["player1"], notification.PlayerNames);
    }

    [Fact]
    public void ReadsAnEmptyNotificationList()
    {
        Assert.True(SnapshotResponseReader.TryRead(
            """{"requestId":"x","worldKey":"y","notifications":[]}""",
            out var response,
            out _));

        Assert.Empty(response.Notifications);
    }

    [Theory]
    [InlineData("")]
    [InlineData("not json")]
    [InlineData("[]")]
    [InlineData("""{"requestId":"x"}""")]
    [InlineData("""{"notifications":{}}""")]
    [InlineData("""{"notifications":[{"audience":"players"}]}""")]
    [InlineData("""{"notifications":[{"audience":"players","message":""}]}""")]
    [InlineData("""{"notifications":[{"message":"hi"}]}""")]
    public void MalformedResponsesAreRejected(string json)
    {
        Assert.False(SnapshotResponseReader.TryRead(json, out var response, out var error));
        Assert.Null(response);
        Assert.NotNull(error);
    }
}
