#nullable disable
using System;
using System.Globalization;
using System.IO;
using System.Text;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// HTTP 応答 body の読み取り。
    ///
    /// **上限付きで読む。** Adapter の queue 上限は「body を全部読んでパースし終えた後」に
    /// しか効かないため、上限なしで読むと、不正な（あるいは侵害された）Bridge が
    /// worker thread に無制限のメモリ確保をさせられる。それは
    /// 「ゲームループをブロックしない」という前提 (docs/design.md §12, AC-18) も壊す。
    ///
    /// TShock に依存しないため、ここは通常の unit test の対象になる。
    /// 実際に <see cref="System.Net.HttpWebResponse"/> から読むのは
    /// <c>HttpSnapshotTransport.TShock.cs</c> 側。
    /// </summary>
    public static class ResponseBodyReader
    {
        /// <summary>
        /// 応答 body の既定上限 (1 MiB)。
        ///
        /// contracts/snapshot-response-v1.schema.json の応答は
        /// <c>requestId</c> / <c>worldKey</c> と通知文の配列だけであり、
        /// 実運用では数 KB に収まる。1 MiB は「明らかに異常」と判定できる余裕を
        /// 持たせた値で、超えたものは transport failure として扱う。
        /// </summary>
        public const int DefaultMaxResponseBytes = 1024 * 1024;

        private const int ChunkSize = 8 * 1024;

        /// <summary>
        /// <paramref name="maxBytes"/> を超えない範囲で body を読み切る。
        /// 上限を超えた時点で読み取りを打ち切り false を返す（残りは読まない）。
        /// </summary>
        public static bool TryRead(Stream stream, int maxBytes, out string body, out string error)
        {
            body = null;
            error = null;

            if (stream == null)
            {
                // 応答 body が無いのは異常ではない（例: 204）。空文字として扱う。
                body = string.Empty;

                return true;
            }

            if (maxBytes < 1)
            {
                maxBytes = 1;
            }

            var buffer = new byte[ChunkSize];

            using (var accumulated = new MemoryStream())
            {
                while (true)
                {
                    var read = stream.Read(buffer, 0, buffer.Length);

                    if (read <= 0)
                    {
                        break;
                    }

                    if (accumulated.Length + read > maxBytes)
                    {
                        error = string.Format(
                            CultureInfo.InvariantCulture,
                            "bridge response exceeded the {0} byte limit; it was not read.",
                            maxBytes);

                        return false;
                    }

                    accumulated.Write(buffer, 0, read);
                }

                body = new UTF8Encoding(false).GetString(accumulated.ToArray());

                return true;
            }
        }
    }
}
