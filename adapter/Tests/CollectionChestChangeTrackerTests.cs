using TerrariaBacklog.Adapter.Hooks;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §7.3 / tasks/08 §6.2-§6.4。
///
/// hook から読み取ってよいのは「Collection Chest が dirty になった可能性」と
/// 「操作した player」の2点だけ。debounce 後に Chest を読み直す契機を作るのがこのクラス。
///
/// 操作経路の対応 (TShock 4.3.13 / Terraria 1.3.0.8 の実バイナリで確認):
/// <list type="bullet">
///   <item>通常ドラッグ / Shift -&gt; packet 32 (<c>PacketTypes.ChestItem</c>)。Chest ID が分かる。</item>
///   <item>Quick Stack -&gt; packet 85 (<c>PacketTypes.ForceItemIntoNearestChest</c>)。
///     サーバー側の <c>Chest.PutItemInNearbyChest</c> が複数 Chest を書き換えるため
///     Chest は特定できない。</item>
/// </list>
/// </summary>
public class CollectionChestChangeTrackerTests
{
    private static readonly DateTime T0 = new(2026, 9, 16, 1, 0, 0, DateTimeKind.Utc);

    private const int CollectionChestId = 7;

    private static bool IsCollectionChest(int chestId) => chestId == CollectionChestId;

    private static CollectionChestChangeTracker NewTracker(int debounceMs = 500, int maxDelayMs = 3000)
        => new(debounceMs, maxDelayMs);

    [Fact]
    public void NothingIsDueWithoutAnyObservation()
    {
        var tracker = NewTracker();

        Assert.False(tracker.HasPending);
        Assert.False(tracker.TryTakeDueChange(T0, IsCollectionChest, out _));
    }

    [Fact]
    public void ChangeIsHeldUntilTheDebounceWindowElapses()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0);

        Assert.True(tracker.HasPending);
        Assert.False(tracker.TryTakeDueChange(T0.AddMilliseconds(499), IsCollectionChest, out _));
        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(500), IsCollectionChest, out var trigger));
        Assert.Equal(new[] { "player1" }, trigger.PlayerNames);

        // 取り出したら空になる。
        Assert.False(tracker.HasPending);
        Assert.False(tracker.TryTakeDueChange(T0.AddMinutes(1), IsCollectionChest, out _));
    }

    [Fact]
    public void FurtherChangesExtendTheQuietPeriod()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0);
        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0.AddMilliseconds(400));

        Assert.False(tracker.TryTakeDueChange(T0.AddMilliseconds(600), IsCollectionChest, out _));
        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(900), IsCollectionChest, out _));
    }

    [Fact]
    public void ContinuousActivityStillFiresAtTheMaximumDelay()
    {
        var tracker = NewTracker(debounceMs: 500, maxDelayMs: 1000);

        for (var offset = 0; offset <= 1000; offset += 100)
        {
            tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0.AddMilliseconds(offset));
        }

        // quiet period は満たさないが、最初の dirty から maxDelay が経過している。
        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(1000), IsCollectionChest, out _));
    }

    [Fact]
    public void PlayersInTheSameWindowAreDeduplicatedAndAggregated()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0);
        tracker.MarkChestSlotChanged(CollectionChestId, "player2", T0.AddMilliseconds(100));
        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0.AddMilliseconds(200));

        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(800), IsCollectionChest, out var trigger));

        Assert.Equal(new[] { "player1", "player2" }, trigger.PlayerNames);
    }

    [Fact]
    public void DragShiftAndQuickStackConvergeOnASingleTrigger()
    {
        var tracker = NewTracker();

        // 通常ドラッグ (packet 32)
        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0);
        // Shift 操作 (同じ packet 32、別スロット)
        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0.AddMilliseconds(50));
        // Quick Stack (packet 85、対象 Chest は不明)
        tracker.MarkUnknownChestsChanged("player2", T0.AddMilliseconds(100));

        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(700), IsCollectionChest, out var trigger));

        // 3経路すべてが 1 つの「読み直し契機」に収束する。
        Assert.Equal(new[] { "player1", "player2" }, trigger.PlayerNames);
        Assert.Equal(0, trigger.UnattributedObservations);
        Assert.False(tracker.HasPending);
    }

    [Fact]
    public void ChangesToOtherChestsAreIgnored()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(chestId: 1, "player1", T0);
        tracker.MarkChestSlotChanged(chestId: 2, "player1", T0.AddMilliseconds(10));

        // 設定名と一致する Chest が絡んでいないので Snapshot は作らない。
        Assert.False(tracker.TryTakeDueChange(T0.AddMilliseconds(800), IsCollectionChest, out var trigger));
        Assert.Null(trigger);
        Assert.False(tracker.HasPending);
    }

    [Fact]
    public void OneConfiguredChestAmongOthersStillTriggers()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(chestId: 1, "player1", T0);
        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0.AddMilliseconds(10));

        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(800), IsCollectionChest, out _));
    }

    [Fact]
    public void QuickStackAlwaysTriggersARereadEvenWithoutAKnownChest()
    {
        var tracker = NewTracker();

        // Quick Stack はどの Chest が dirty になるか packet から決まらないため、
        // 設定された Collection Chest を無条件に読み直す。
        tracker.MarkUnknownChestsChanged("player1", T0);

        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(600), _ => false, out var trigger));
        Assert.Equal(new[] { "player1" }, trigger.PlayerNames);
    }

    [Fact]
    public void UnattributableObservationsAreCountedNotGuessed()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(CollectionChestId, null, T0);

        Assert.True(tracker.TryTakeDueChange(T0.AddMilliseconds(600), IsCollectionChest, out var trigger));

        // 推測で player を埋めない (tasks/08 §6.3)。
        Assert.Empty(trigger.PlayerNames);
        Assert.Equal(1, trigger.UnattributedObservations);
    }

    [Fact]
    public void IgnoredWindowDoesNotSwallowLaterRelevantChanges()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(chestId: 1, "player1", T0);
        Assert.False(tracker.TryTakeDueChange(T0.AddMilliseconds(800), IsCollectionChest, out _));

        tracker.MarkChestSlotChanged(CollectionChestId, "player2", T0.AddSeconds(5));
        Assert.True(tracker.TryTakeDueChange(T0.AddSeconds(6), IsCollectionChest, out var trigger));
        Assert.Equal(new[] { "player2" }, trigger.PlayerNames);
    }

    [Fact]
    public void ResetDropsEverything()
    {
        var tracker = NewTracker();

        tracker.MarkChestSlotChanged(CollectionChestId, "player1", T0);
        tracker.Reset();

        Assert.False(tracker.HasPending);
        Assert.False(tracker.TryTakeDueChange(T0.AddMinutes(1), IsCollectionChest, out _));
    }
}
