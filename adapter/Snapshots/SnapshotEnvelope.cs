#nullable disable
using System;
using System.Collections.Generic;
using TerrariaBacklog.Adapter.Json;

namespace TerrariaBacklog.Adapter.Snapshots
{
    /// <summary>
    /// Adapter -&gt; PHP Snapshot request (docs/design.md §6.2,
    /// contracts/snapshot-v1.schema.json)。
    ///
    /// Adapter は Achievement Key を作らない。ここに入るのは
    /// 「観測した raw state」と「その契機」だけである。
    /// </summary>
    public sealed class SnapshotEnvelope
    {
        public const int SchemaVersion = 1;

        private readonly List<string> _triggerPlayerNames;

        public SnapshotEnvelope(
            Guid requestId,
            string reason,
            RuntimeVersions runtime,
            WorldObservation observation,
            IEnumerable<string> triggerPlayerNames,
            bool recoveryPending)
        {
            if (!SnapshotReason.IsValid(reason))
            {
                throw new ArgumentException("unsupported snapshot reason: " + reason, "reason");
            }

            if (runtime == null)
            {
                throw new ArgumentNullException("runtime");
            }

            if (observation == null)
            {
                throw new ArgumentNullException("observation");
            }

            RequestId = requestId;
            Reason = reason;
            Runtime = runtime;
            Observation = observation;
            RecoveryPending = recoveryPending;
            _triggerPlayerNames = triggerPlayerNames == null
                ? new List<string>()
                : new List<string>(triggerPlayerNames);
        }

        public Guid RequestId { get; private set; }

        public string Reason { get; private set; }

        public RuntimeVersions Runtime { get; private set; }

        public WorldObservation Observation { get; private set; }

        /// <summary>
        /// docs/design.md §15.2: Player 情報を含まないワールド単位の復旧待ちフラグ。
        /// periodic / manual にのみ載せる。
        /// </summary>
        public bool RecoveryPending { get; private set; }

        /// <summary>
        /// docs/design.md §7.3 / §15.2: 同じ debounce window で観測した操作 Player を
        /// 重複排除して集約したもの。**取得できなかった経路を推測で埋めない。**
        /// </summary>
        public IList<string> TriggerPlayerNames
        {
            get { return _triggerPlayerNames; }
        }

        public string WorldKey
        {
            get { return Observation.WorldKey; }
        }

        /// <summary>
        /// contracts/snapshot-v1.schema.json に適合する JSON を生成する。
        ///
        /// 出力順は schema の <c>required</c> 順に揃え、diff / test を安定させる。
        /// </summary>
        public string ToJson()
        {
            var writer = new JsonWriter();

            writer.StartObject();

            writer.Name("schemaVersion");
            writer.Value(SchemaVersion);

            writer.Name("requestId");
            writer.Value(RequestId.ToString("D"));

            writer.Name("reason");
            writer.Value(Reason);

            writer.Name("observedAt");
            writer.Value(FormatTimestamp(Observation.ObservedAt));

            if (RecoveryPending)
            {
                // 省略時は false 扱い (docs/design.md §15.2)。true のときだけ載せる。
                writer.Name("recoveryPending");
                writer.Value(true);
            }

            writer.Name("runtime");
            writer.StartObject();
            writer.Name("adapterVersion");
            writer.Value(Runtime.AdapterVersion);
            writer.Name("tshockVersion");
            writer.Value(Runtime.TShockVersion);
            writer.Name("terrariaVersion");
            writer.Value(Runtime.TerrariaVersion);
            writer.EndObject();

            writer.Name("world");
            writer.StartObject();
            writer.Name("key");
            writer.Value(Observation.WorldKey);
            writer.Name("terrariaWorldId");
            writer.Value(Observation.TerrariaWorldId);

            if (Observation.WorldName != null)
            {
                writer.Name("name");
                writer.Value(Observation.WorldName);
            }

            writer.EndObject();

            writer.Name("collectionChestName");
            writer.Value(Observation.CollectionChestName);

            writer.Name("flags");
            writer.StartObject();

            for (var i = 0; i < Observation.Flags.Count; i++)
            {
                writer.Name(Observation.Flags[i].Key);
                writer.Value(Observation.Flags[i].Value);
            }

            writer.EndObject();

            writer.Name("collectionChests");
            writer.StartArray();

            for (var i = 0; i < Observation.CollectionChests.Count; i++)
            {
                var chest = Observation.CollectionChests[i];

                writer.StartObject();
                writer.Name("x");
                writer.Value(chest.X);
                writer.Name("y");
                writer.Value(chest.Y);
                writer.Name("name");
                writer.Value(chest.Name);
                writer.Name("items");
                writer.StartArray();

                for (var j = 0; j < chest.Items.Count; j++)
                {
                    var item = chest.Items[j];

                    writer.StartObject();
                    writer.Name("type");
                    writer.Value(item.Type);
                    writer.Name("stack");
                    writer.Value(item.Stack);

                    if (item.Name != null)
                    {
                        writer.Name("name");
                        writer.Value(item.Name);
                    }

                    writer.EndObject();
                }

                writer.EndArray();
                writer.EndObject();
            }

            writer.EndArray();

            if (_triggerPlayerNames.Count > 0)
            {
                writer.Name("trigger");
                writer.StartObject();
                writer.Name("playerNames");
                writer.StartArray();

                for (var i = 0; i < _triggerPlayerNames.Count; i++)
                {
                    writer.Value(_triggerPlayerNames[i]);
                }

                writer.EndArray();
                writer.EndObject();
            }

            writer.EndObject();

            return writer.ToString();
        }

        /// <summary>
        /// JSON Schema の <c>format: date-time</c> / Bridge の
        /// <c>SnapshotRequestParser::DATE_TIME_PATTERN</c> に合わせた ISO 8601。
        /// offset 付きであることが必須。
        /// </summary>
        public static string FormatTimestamp(DateTimeOffset value)
        {
            return value.ToString("yyyy-MM-dd'T'HH:mm:ss.fffzzz", System.Globalization.CultureInfo.InvariantCulture);
        }
    }

    /// <summary>docs/design.md §2.3 の runtime 3点。</summary>
    public sealed class RuntimeVersions
    {
        public RuntimeVersions(string adapterVersion, string tshockVersion, string terrariaVersion)
        {
            AdapterVersion = adapterVersion;
            TShockVersion = tshockVersion;
            TerrariaVersion = terrariaVersion;
        }

        public string AdapterVersion { get; private set; }

        public string TShockVersion { get; private set; }

        public string TerrariaVersion { get; private set; }
    }
}
