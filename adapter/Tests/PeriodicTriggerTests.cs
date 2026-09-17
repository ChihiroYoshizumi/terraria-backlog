using TerrariaBacklog.Adapter.Json;
using TerrariaBacklog.Adapter.Runtime;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>docs/design.md §7.4: periodic reconciliation の発火判定。</summary>
public class PeriodicTriggerTests
{
    private static readonly DateTime T0 = new(2026, 9, 16, 1, 0, 0, DateTimeKind.Utc);

    [Fact]
    public void DoesNotFireBeforeBeingArmed()
    {
        var trigger = new PeriodicTrigger(60);

        Assert.False(trigger.TryTakeDue(T0.AddHours(1)));
    }

    [Fact]
    public void FiresOncePerInterval()
    {
        var trigger = new PeriodicTrigger(60);
        trigger.Arm(T0);

        Assert.False(trigger.TryTakeDue(T0.AddSeconds(59)));
        Assert.True(trigger.TryTakeDue(T0.AddSeconds(60)));
        Assert.False(trigger.TryTakeDue(T0.AddSeconds(61)));
        Assert.True(trigger.TryTakeDue(T0.AddSeconds(120)));
    }

    [Fact]
    public void DoesNotBurstAfterALongPause()
    {
        var trigger = new PeriodicTrigger(60);
        trigger.Arm(T0);

        Assert.True(trigger.TryTakeDue(T0.AddHours(1)));
        Assert.False(trigger.TryTakeDue(T0.AddHours(1).AddSeconds(1)));
    }

    [Fact]
    public void IntervalIsConfigurable()
    {
        var trigger = new PeriodicTrigger(5);
        trigger.Arm(T0);

        Assert.True(trigger.TryTakeDue(T0.AddSeconds(5)));
    }

    [Fact]
    public void RejectsANonPositiveInterval()
    {
        Assert.Throws<ArgumentOutOfRangeException>(() => new PeriodicTrigger(0));
    }
}

/// <summary>
/// 外部ライブラリを持たない最小 JSON 実装の健全性。
/// Snapshot の contract 適合はここに依存するため、単体でも固定しておく。
/// </summary>
public class JsonTests
{
    [Fact]
    public void WriterProducesParsableJson()
    {
        var writer = new JsonWriter();
        writer.StartObject();
        writer.Name("a");
        writer.Value(1);
        writer.Name("b");
        writer.StartArray();
        writer.Value(true);
        writer.Value("x");
        writer.StartObject();
        writer.Name("c");
        writer.Value((string?)null!);
        writer.EndObject();
        writer.EndArray();
        writer.EndObject();

        Assert.Equal("""{"a":1,"b":[true,"x",{"c":null}]}""", writer.ToString());
    }

    [Theory]
    [InlineData("\"", "\\\"")]
    [InlineData("\\", "\\\\")]
    [InlineData("\n", "\\n")]
    [InlineData("\t", "\\t")]
    [InlineData("", "\\u0001")]
    public void WriterEscapesControlCharacters(string input, string escaped)
    {
        var writer = new JsonWriter();
        writer.Value(input);

        Assert.Equal("\"" + escaped + "\"", writer.ToString());
    }

    [Fact]
    public void ParserRoundTripsNestedStructures()
    {
        Assert.True(JsonParser.TryParse(
            """{"a":[1,2,{"b":"c"}],"d":null,"e":false}""",
            out var value,
            out var error));

        Assert.Null(error);

        var root = Assert.IsType<Dictionary<string, object>>(value);
        var array = Assert.IsType<List<object>>(root["a"]);
        Assert.Equal(3, array.Count);
        Assert.Equal(1d, array[0]);

        var nested = Assert.IsType<Dictionary<string, object>>(array[2]);
        Assert.Equal("c", nested["b"]);
        Assert.Null(root["d"]);
        Assert.Equal(false, root["e"]);
    }

    [Theory]
    [InlineData("")]
    [InlineData("{")]
    [InlineData("{\"a\"}")]
    [InlineData("{\"a\":1,}")]
    [InlineData("[1,2")]
    [InlineData("{} trailing")]
    [InlineData("\"unterminated")]
    public void ParserRejectsMalformedInputWithoutThrowing(string json)
    {
        Assert.False(JsonParser.TryParse(json, out var value, out var error));
        Assert.Null(value);
        Assert.NotNull(error);
    }

    [Fact]
    public void ParserDecodesEscapeSequences()
    {
        Assert.True(JsonParser.TryParse("\"a\\u0041\\n\\\"b\\\"\"", out var value, out _));

        Assert.Equal("aA\n\"b\"", value);
    }
}
