using System.Text;
using TerrariaBacklog.Adapter.Transport;
using Xunit;

namespace TerrariaBacklog.Adapter.Tests;

/// <summary>
/// docs/design.md §12 / AC-18。
///
/// 応答 body は上限付きで読む。上限が無いと、不正な（あるいは侵害された）Bridge が
/// worker thread に無制限のメモリ確保をさせられる。queue 上限は body を読み切った後に
/// しか効かないため、読み取り時点で止める必要がある。
/// </summary>
public class ResponseBodyReaderTests
{
    private static MemoryStream Body(int bytes) => new(Encoding.ASCII.GetBytes(new string('a', bytes)));

    [Fact]
    public void ReadsABodyWithinTheLimit()
    {
        using var stream = new MemoryStream(Encoding.UTF8.GetBytes("""{"notifications":[]}"""));

        Assert.True(ResponseBodyReader.TryRead(stream, 1024, out var body, out var error));
        Assert.Null(error);
        Assert.Equal("""{"notifications":[]}""", body);
    }

    [Fact]
    public void ReadsMultibyteCharactersAsUtf8()
    {
        using var stream = new MemoryStream(Encoding.UTF8.GetBytes("[Backlog] 登録しました。"));

        Assert.True(ResponseBodyReader.TryRead(stream, 1024, out var body, out _));
        Assert.Equal("[Backlog] 登録しました。", body);
    }

    [Fact]
    public void AnAbsentBodyIsAnEmptyString()
    {
        Assert.True(ResponseBodyReader.TryRead(null, 1024, out var body, out var error));
        Assert.Equal(string.Empty, body);
        Assert.Null(error);
    }

    [Fact]
    public void ABodyExactlyAtTheLimitIsStillRead()
    {
        using var stream = Body(64);

        Assert.True(ResponseBodyReader.TryRead(stream, 64, out var body, out _));
        Assert.Equal(64, body.Length);
    }

    [Fact]
    public void ABodyOverTheLimitIsATransportFailure()
    {
        using var stream = Body(65);

        Assert.False(ResponseBodyReader.TryRead(stream, 64, out var body, out var error));
        Assert.Null(body);
        Assert.NotNull(error);
        Assert.Contains("64", error, StringComparison.Ordinal);
    }

    [Fact]
    public void AnOversizedBodyIsNotReadToTheEnd()
    {
        // 読み切ってからサイズを判定するのでは、その時点で既にメモリを取られている。
        // 上限を超えた時点で読み取りを打ち切ること。
        using var stream = new CountingStream(Body(8 * 1024 * 1024));

        Assert.False(ResponseBodyReader.TryRead(stream, 64 * 1024, out _, out _));
        Assert.True(
            stream.BytesRead <= 64 * 1024 + 8 * 1024,
            $"the reader consumed {stream.BytesRead} bytes past the limit.");
    }

    [Fact]
    public void TheDefaultLimitIsOneMebibyte()
    {
        Assert.Equal(1024 * 1024, ResponseBodyReader.DefaultMaxResponseBytes);
    }

    private sealed class CountingStream(Stream inner) : Stream
    {
        public long BytesRead { get; private set; }

        public override bool CanRead => true;

        public override bool CanSeek => false;

        public override bool CanWrite => false;

        public override long Length => inner.Length;

        public override long Position
        {
            get => inner.Position;
            set => throw new NotSupportedException();
        }

        public override int Read(byte[] buffer, int offset, int count)
        {
            var read = inner.Read(buffer, offset, count);
            BytesRead += read;

            return read;
        }

        public override void Flush() => inner.Flush();

        public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();

        public override void SetLength(long value) => throw new NotSupportedException();

        public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();

        protected override void Dispose(bool disposing)
        {
            if (disposing)
            {
                inner.Dispose();
            }

            base.Dispose(disposing);
        }
    }
}
