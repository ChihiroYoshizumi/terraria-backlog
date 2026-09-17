#nullable disable
using System;
using System.Collections.Generic;
using System.Globalization;
using TerrariaBacklog.Adapter.Json;

namespace TerrariaBacklog.Adapter.Configuration
{
    /// <summary>
    /// Adapter 設定 (docs/design.md §7.1)。
    ///
    /// <see cref="CollectionChestName"/> は設定値であり、固定文字列を監視条件へ
    /// 埋め込まない。既定値だけが <c>BACKLOG_COLLECTION</c> である。
    ///
    /// Adapter Token は秘密情報なので <see cref="ToString"/> やログに出さない。
    /// </summary>
    public sealed class AdapterSettings
    {
        public const string DefaultCollectionChestName = "BACKLOG_COLLECTION";
        public const string DefaultBridgeUrl = "http://127.0.0.1:8080";
        public const int DefaultReconciliationIntervalSeconds = 60;
        public const int DefaultChestChangeDebounceMilliseconds = 500;
        public const int DefaultRequestTimeoutSeconds = 10;

        /// <summary>
        /// debounce window が連続入力で無限に延びないための上限。
        /// 最初の dirty から この時間が経過したら必ず capture する。
        /// </summary>
        public const int DefaultChestChangeMaxDelayMilliseconds = 3000;

        public AdapterSettings()
        {
            BridgeUrl = DefaultBridgeUrl;
            AdapterToken = string.Empty;
            WorldKeyOverride = null;
            CollectionChestName = DefaultCollectionChestName;
            ReconciliationIntervalSeconds = DefaultReconciliationIntervalSeconds;
            ChestChangeDebounceMilliseconds = DefaultChestChangeDebounceMilliseconds;
            ChestChangeMaxDelayMilliseconds = DefaultChestChangeMaxDelayMilliseconds;
            RequestTimeoutSeconds = DefaultRequestTimeoutSeconds;
        }

        public string BridgeUrl { get; set; }

        /// <summary>PHP Bridge の Bearer Token。Backlog API Key とは別の秘密情報。</summary>
        public string AdapterToken { get; set; }

        /// <summary>null / 空なら <c>terraria:&lt;Main.worldID&gt;</c> を使う (docs/design.md §5.1)。</summary>
        public string WorldKeyOverride { get; set; }

        public string CollectionChestName { get; set; }

        public int ReconciliationIntervalSeconds { get; set; }

        public int ChestChangeDebounceMilliseconds { get; set; }

        public int ChestChangeMaxDelayMilliseconds { get; set; }

        public int RequestTimeoutSeconds { get; set; }

        /// <summary>
        /// 設定不備は fail closed とする (docs/design.md §18.4)。
        /// 問題があれば人間が読める理由を返す。問題なければ空配列。
        /// </summary>
        public IList<string> Validate()
        {
            var problems = new List<string>();

            if (string.IsNullOrEmpty(BridgeUrl) || BridgeUrl.Trim().Length == 0)
            {
                problems.Add("BridgeUrl is empty.");
            }
            else
            {
                Uri parsed;

                if (!Uri.TryCreate(BridgeUrl, UriKind.Absolute, out parsed) ||
                    (parsed.Scheme != Uri.UriSchemeHttp && parsed.Scheme != Uri.UriSchemeHttps))
                {
                    problems.Add("BridgeUrl must be an absolute http(s) URL.");
                }
            }

            if (string.IsNullOrEmpty(AdapterToken) || AdapterToken.Trim().Length == 0)
            {
                problems.Add("AdapterToken is empty. Set it in the config file or the "
                    + AdapterSettingsFile.TokenEnvironmentVariable + " environment variable.");
            }

            if (WorldKeyOverride != null && WorldKeyOverride.Trim().Length == 0)
            {
                problems.Add("WorldKeyOverride must be null or a non-blank string.");
            }

            if (string.IsNullOrEmpty(CollectionChestName) || CollectionChestName.Trim().Length == 0)
            {
                problems.Add("CollectionChestName is empty.");
            }

            if (ReconciliationIntervalSeconds < 5)
            {
                problems.Add("ReconciliationIntervalSeconds must be at least 5.");
            }

            if (ChestChangeDebounceMilliseconds < 0)
            {
                problems.Add("ChestChangeDebounceMilliseconds must not be negative.");
            }

            if (ChestChangeMaxDelayMilliseconds < ChestChangeDebounceMilliseconds)
            {
                problems.Add("ChestChangeMaxDelayMilliseconds must be >= ChestChangeDebounceMilliseconds.");
            }

            if (RequestTimeoutSeconds < 1)
            {
                problems.Add("RequestTimeoutSeconds must be at least 1.");
            }

            return problems;
        }

        /// <summary>
        /// Snapshot の送信先 URL (docs/design.md §6.1)。
        /// </summary>
        public string BuildSnapshotUrl(string worldKey)
        {
            if (worldKey == null)
            {
                throw new ArgumentNullException("worldKey");
            }

            return BridgeUrl.TrimEnd('/')
                + "/api/v1/worlds/"
                + EncodeWorldKeySegment(worldKey)
                + "/snapshots";
        }

        /// <summary>
        /// World Key を path segment へ入れる。
        ///
        /// 既定の World Key は <c>terraria:123456789</c> であり、<c>:</c> は RFC 3986 の
        /// pchar として path segment にそのまま置ける。Bridge 側 route の制約
        /// (<c>[A-Za-z0-9:._-]</c>) とも一致するため、<c>:</c> だけは escape しない。
        /// </summary>
        public static string EncodeWorldKeySegment(string worldKey)
        {
            return Uri.EscapeDataString(worldKey).Replace("%3A", ":").Replace("%3a", ":");
        }

        /// <summary>設定ファイルへ書き出す JSON。Token は含めない選択もできる。</summary>
        public string ToJson(bool includeToken)
        {
            var writer = new JsonWriter();
            writer.StartObject();
            writer.Name("BridgeUrl");
            writer.Value(BridgeUrl);
            writer.Name("AdapterToken");
            writer.Value(includeToken ? AdapterToken : string.Empty);
            writer.Name("WorldKeyOverride");
            writer.Value(WorldKeyOverride);
            writer.Name("CollectionChestName");
            writer.Value(CollectionChestName);
            writer.Name("ReconciliationIntervalSeconds");
            writer.Value(ReconciliationIntervalSeconds);
            writer.Name("ChestChangeDebounceMilliseconds");
            writer.Value(ChestChangeDebounceMilliseconds);
            writer.Name("ChestChangeMaxDelayMilliseconds");
            writer.Value(ChestChangeMaxDelayMilliseconds);
            writer.Name("RequestTimeoutSeconds");
            writer.Value(RequestTimeoutSeconds);
            writer.EndObject();

            return writer.ToString();
        }

        /// <summary>
        /// 設定 JSON を読む。未知の key は将来互換のため読み飛ばす。
        /// </summary>
        public static AdapterSettings FromJson(string json, out string error)
        {
            error = null;
            object parsed;

            if (!JsonParser.TryParse(json, out parsed, out error))
            {
                return null;
            }

            var root = parsed as Dictionary<string, object>;

            if (root == null)
            {
                error = "configuration must be a JSON object.";
                return null;
            }

            var settings = new AdapterSettings();

            settings.BridgeUrl = ReadString(root, "BridgeUrl", settings.BridgeUrl);
            settings.AdapterToken = ReadString(root, "AdapterToken", settings.AdapterToken);
            settings.WorldKeyOverride = ReadString(root, "WorldKeyOverride", null);
            settings.CollectionChestName = ReadString(root, "CollectionChestName", settings.CollectionChestName);
            settings.ReconciliationIntervalSeconds =
                ReadInt(root, "ReconciliationIntervalSeconds", settings.ReconciliationIntervalSeconds);
            settings.ChestChangeDebounceMilliseconds =
                ReadInt(root, "ChestChangeDebounceMilliseconds", settings.ChestChangeDebounceMilliseconds);
            settings.ChestChangeMaxDelayMilliseconds =
                ReadInt(root, "ChestChangeMaxDelayMilliseconds", settings.ChestChangeMaxDelayMilliseconds);
            settings.RequestTimeoutSeconds = ReadInt(root, "RequestTimeoutSeconds", settings.RequestTimeoutSeconds);

            return settings;
        }

        private static string ReadString(IDictionary<string, object> root, string name, string fallback)
        {
            object value;

            if (!root.TryGetValue(name, out value) || value == null)
            {
                return fallback;
            }

            var text = value as string;

            return text ?? fallback;
        }

        private static int ReadInt(IDictionary<string, object> root, string name, int fallback)
        {
            object value;

            if (!root.TryGetValue(name, out value) || !(value is double))
            {
                return fallback;
            }

            var number = (double)value;

            if (number < int.MinValue || number > int.MaxValue)
            {
                return fallback;
            }

            return (int)Math.Round(number, MidpointRounding.AwayFromZero);
        }

        public override string ToString()
        {
            return string.Format(
                CultureInfo.InvariantCulture,
                "BridgeUrl={0}, CollectionChestName={1}, ReconciliationIntervalSeconds={2}, "
                + "ChestChangeDebounceMilliseconds={3}, WorldKeyOverride={4}, AdapterToken=<redacted>",
                BridgeUrl,
                CollectionChestName,
                ReconciliationIntervalSeconds,
                ChestChangeDebounceMilliseconds,
                WorldKeyOverride ?? "<none>");
        }
    }
}
