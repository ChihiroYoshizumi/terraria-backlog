using TerrariaBacklog.Adapter.Snapshots;
using TerrariaBacklog.Adapter.Transport;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §7.6: 未送信 Snapshot の coalescing。
/// </summary>
public class SnapshotDispatchQueueTests
{
    private static PendingSnapshot Collection(bool hardMode, params string[] players)
        => new(SnapshotReason.CollectionChange, Observations.Build(hardMode: hardMode), players);

    private static PendingSnapshot State(string reason, bool hardMode = false)
        => new(reason, Observations.Build(hardMode: hardMode), null);

    [Fact]
    public void StateSnapshotsCoalesceToTheLatest()
    {
        var queue = new SnapshotDispatchQueue();

        queue.Enqueue(State(SnapshotReason.Startup));
        queue.Enqueue(State(SnapshotReason.Periodic));
        queue.Enqueue(State(SnapshotReason.WorldChange, hardMode: true));

        Assert.Equal(1, queue.PendingCount);
        Assert.True(queue.TryDequeue(out var pending));
        Assert.Equal(SnapshotReason.WorldChange, pending.Reason);
        Assert.Contains(pending.Observation.Flags, f => f is { Key: "hardMode", Value: true });
        Assert.False(queue.TryDequeue(out _));
    }

    [Fact]
    public void PeriodicDoesNotDiscardAPendingCollectionTrigger()
    {
        var queue = new SnapshotDispatchQueue();

        queue.Enqueue(Collection(false, "player1"));
        queue.Enqueue(State(SnapshotReason.Periodic));
        queue.Enqueue(State(SnapshotReason.Periodic));

        Assert.Equal(2, queue.PendingCount);
        Assert.True(queue.HasPendingCollectionChange);

        // ACK routing を持つ collection_change が先に出る。
        Assert.True(queue.TryDequeue(out var first));
        Assert.Equal(SnapshotReason.CollectionChange, first.Reason);
        Assert.Equal(new[] { "player1" }, first.PlayerNames);

        Assert.True(queue.TryDequeue(out var second));
        Assert.Equal(SnapshotReason.Periodic, second.Reason);
        Assert.Empty(second.PlayerNames);
    }

    [Fact]
    public void ConsecutiveCollectionChangesKeepLatestStateAndUnionOfPlayers()
    {
        var queue = new SnapshotDispatchQueue();

        queue.Enqueue(Collection(false, "player1"));
        queue.Enqueue(Collection(false, "player2"));
        queue.Enqueue(Collection(true, "player1", "player3"));

        Assert.Equal(1, queue.PendingCount);
        Assert.True(queue.TryDequeue(out var pending));

        Assert.Equal(new[] { "player1", "player2", "player3" }, pending.PlayerNames);
        Assert.Contains(pending.Observation.Flags, f => f is { Key: "hardMode", Value: true });
    }

    [Fact]
    public void ManualSnapshotIsNotAbsorbedByStateSnapshots()
    {
        var queue = new SnapshotDispatchQueue();

        queue.Enqueue(State(SnapshotReason.Manual));
        queue.Enqueue(State(SnapshotReason.Periodic));

        Assert.Equal(2, queue.PendingCount);

        Assert.True(queue.TryDequeue(out var first));
        Assert.Equal(SnapshotReason.Manual, first.Reason);

        Assert.True(queue.TryDequeue(out var second));
        Assert.Equal(SnapshotReason.Periodic, second.Reason);
    }

    [Fact]
    public void QueueNeverGrowsBeyondThreeSlots()
    {
        var queue = new SnapshotDispatchQueue();

        for (var i = 0; i < 100; i++)
        {
            queue.Enqueue(Collection(false, "player" + i));
            queue.Enqueue(State(SnapshotReason.Periodic));
            queue.Enqueue(State(SnapshotReason.Manual));
        }

        // 永続 Queue を持たないため、メモリ上の未送信量も有界にする。
        Assert.Equal(3, queue.PendingCount);
    }

    [Fact]
    public void ClearDropsEverything()
    {
        var queue = new SnapshotDispatchQueue();

        queue.Enqueue(Collection(false, "player1"));
        queue.Clear();

        Assert.Equal(0, queue.PendingCount);
        Assert.False(queue.TryDequeue(out _));
    }

    [Fact]
    public void UnsupportedReasonIsRejected()
    {
        Assert.Throws<ArgumentException>(() =>
            new PendingSnapshot("boss_defeated", Observations.Build(), null));
    }
}
