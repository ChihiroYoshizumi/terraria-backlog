#nullable disable
namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// HTTP 送信の抽象。実装は <c>HttpSnapshotTransport.TShock.cs</c>。
    /// テストからは fake を差し込む。
    /// </summary>
    public interface ISnapshotTransport
    {
        /// <summary>
        /// 同期送信する。**game thread からは呼ばない**（背後の worker thread 専用）。
        /// 例外を投げず、失敗も <see cref="SnapshotTransportResult"/> で返すこと。
        /// </summary>
        SnapshotTransportResult Send(string url, string bearerToken, string jsonBody);
    }

    /// <summary>
    /// 送信結果。Adapter 自身が成功 ACK を生成しないため、
    /// 「応答を受け取れたか」と「それが成功応答か」を分けて持つ。
    /// </summary>
    public sealed class SnapshotTransportResult
    {
        private SnapshotTransportResult(bool completed, int statusCode, string body, string error)
        {
            Completed = completed;
            StatusCode = statusCode;
            Body = body;
            Error = error;
        }

        /// <summary>HTTP 応答を受け取れたか（timeout / 接続失敗は false）。</summary>
        public bool Completed { get; private set; }

        public int StatusCode { get; private set; }

        public string Body { get; private set; }

        /// <summary>失敗理由。秘密情報を含めないこと。</summary>
        public string Error { get; private set; }

        public bool IsSuccess
        {
            get { return Completed && StatusCode >= 200 && StatusCode < 300; }
        }

        public static SnapshotTransportResult FromResponse(int statusCode, string body)
        {
            return new SnapshotTransportResult(true, statusCode, body, null);
        }

        public static SnapshotTransportResult FromFailure(string error)
        {
            return new SnapshotTransportResult(false, 0, null, error);
        }
    }
}
