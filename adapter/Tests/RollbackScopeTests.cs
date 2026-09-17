using TerrariaBacklog.Adapter.Runtime;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// Plugin 初期化の巻き戻し (docs/design.md §18.4 の fail closed)。
///
/// hook 登録の後で初期化が失敗したとき、部分的に登録された callback が残ると、
/// 無効化したはずの Adapter がサーバー稼働中ずっと呼ばれ続ける。
/// TShock 依存を持たないこの scope に巻き戻し手順を寄せて検証する。
/// </summary>
public class RollbackScopeTests
{
    [Fact]
    public void UndoesEveryRegisteredStepInReverseOrder()
    {
        var undone = new List<string>();
        var scope = new RollbackScope();

        scope.Add(() => undone.Add("sender"));
        scope.Add(() => undone.Add("game-post-initialize"));
        scope.Add(() => undone.Add("game-update"));
        scope.Add(() => undone.Add("chest-change-watcher"));

        scope.Rollback();

        Assert.Equal(["chest-change-watcher", "game-update", "game-post-initialize", "sender"], undone);
        Assert.Equal(0, scope.Count);
    }

    [Fact]
    public void AFailureMidwayThroughInitializationUndoesTheStepsAlreadyTaken()
    {
        // 初期化を模した手順。3段階まで登録し、4段階目で throw する。
        var registered = new HashSet<string>();
        var scope = new RollbackScope();

        Exception? failure = null;

        try
        {
            Register(registered, scope, "game-post-initialize");
            Register(registered, scope, "game-update");
            Register(registered, scope, "chest-change-watcher");

            throw new InvalidOperationException("command registration failed");
        }
        catch (InvalidOperationException ex)
        {
            failure = ex;
        }

        Assert.NotNull(failure);
        Assert.Equal(3, registered.Count);

        scope.Rollback();

        Assert.Empty(registered);
    }

    [Fact]
    public void RollbackRunsOnlyOnce()
    {
        var calls = 0;
        var scope = new RollbackScope();

        scope.Add(() => calls++);

        scope.Rollback();
        scope.Rollback();

        Assert.Equal(1, calls);
    }

    [Fact]
    public void AFailingUndoStepNeitherStopsTheRestNorEscapes()
    {
        var undone = new List<string>();
        var log = new RecordingLog();
        var scope = new RollbackScope(log);

        scope.Add(() => undone.Add("first"));
        scope.Add(() => throw new InvalidOperationException("deregister failed"));
        scope.Add(() => undone.Add("last"));

        var failure = Record.Exception(scope.Rollback);

        Assert.Null(failure);
        Assert.Equal(["last", "first"], undone);
        Assert.NotEmpty(log.Errors);
    }

    [Fact]
    public void RollingBackAnEmptyScopeIsANoOp()
    {
        var scope = new RollbackScope();

        Assert.Null(Record.Exception(scope.Rollback));
        Assert.Equal(0, scope.Count);
    }

    private static void Register(ISet<string> registered, RollbackScope scope, string name)
    {
        registered.Add(name);
        scope.Add(() => registered.Remove(name));
    }
}
