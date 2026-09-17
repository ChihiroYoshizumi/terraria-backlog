#nullable disable
using System;
using System.IO;
using System.Net;
using System.Text;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// PHP Bridge への HTTP 送信 (docs/design.md §6.1, §12)。
    ///
    /// <see cref="HttpWebRequest"/> を同期で使う。呼び出すのは
    /// <see cref="SnapshotSender"/> の worker thread だけで、game thread は待たせない。
    ///
    /// 例外は外へ出さず <see cref="SnapshotTransportResult"/> として返す。
    /// Adapter Token はログにも例外メッセージにも出さない。
    /// </summary>
    public sealed class HttpSnapshotTransport : ISnapshotTransport
    {
        private readonly int _timeoutMilliseconds;
        private readonly int _maxResponseBytes;

        public HttpSnapshotTransport(int timeoutSeconds)
            : this(timeoutSeconds, ResponseBodyReader.DefaultMaxResponseBytes)
        {
        }

        /// <param name="maxResponseBytes">
        /// 応答 body の上限。超えた応答は読み切らず transport failure にする
        /// (<see cref="ResponseBodyReader"/>)。
        /// </param>
        public HttpSnapshotTransport(int timeoutSeconds, int maxResponseBytes)
        {
            _timeoutMilliseconds = Math.Max(1, timeoutSeconds) * 1000;
            _maxResponseBytes = maxResponseBytes;
        }

        public SnapshotTransportResult Send(string url, string bearerToken, string jsonBody)
        {
            try
            {
                var request = (HttpWebRequest)WebRequest.Create(url);
                request.Method = "POST";
                request.ContentType = "application/json; charset=utf-8";
                request.Accept = "application/json";
                request.Timeout = _timeoutMilliseconds;
                request.ReadWriteTimeout = _timeoutMilliseconds;
                request.KeepAlive = false;
                request.Headers[HttpRequestHeader.Authorization] = "Bearer " + bearerToken;

                var payload = new UTF8Encoding(false).GetBytes(jsonBody);
                request.ContentLength = payload.Length;

                using (var stream = request.GetRequestStream())
                {
                    stream.Write(payload, 0, payload.Length);
                }

                try
                {
                    using (var response = (HttpWebResponse)request.GetResponse())
                    {
                        return ToResult(response);
                    }
                }
                catch (WebException ex)
                {
                    var errorResponse = ex.Response as HttpWebResponse;

                    if (errorResponse == null)
                    {
                        // timeout / 接続失敗など、応答を受け取れなかったケース。
                        return SnapshotTransportResult.FromFailure(Describe(ex));
                    }

                    using (errorResponse)
                    {
                        return ToResult(errorResponse);
                    }
                }
            }
            catch (Exception ex)
            {
                return SnapshotTransportResult.FromFailure(ex.GetType().Name + ": " + ex.Message);
            }
        }

        private static string Describe(WebException ex)
        {
            return ex.Status + ": " + ex.Message;
        }

        /// <summary>
        /// 応答を結果へ変換する。body が上限を超える場合は読み切らず、
        /// status code に関係なく transport failure として返す。
        /// </summary>
        private SnapshotTransportResult ToResult(HttpWebResponse response)
        {
            string body;
            string error;

            using (var stream = response.GetResponseStream())
            {
                if (!ResponseBodyReader.TryRead(stream, _maxResponseBytes, out body, out error))
                {
                    return SnapshotTransportResult.FromFailure(error);
                }
            }

            return SnapshotTransportResult.FromResponse((int)response.StatusCode, body);
        }
    }
}
