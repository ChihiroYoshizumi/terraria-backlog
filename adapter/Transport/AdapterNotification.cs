#nullable disable
using System;
using System.Collections.Generic;
using TerrariaBacklog.Adapter.Json;

namespace TerrariaBacklog.Adapter.Transport
{
    /// <summary>
    /// PHP が確定した通知命令 (docs/design.md §6.5 /
    /// contracts/snapshot-response-v1.schema.json)。
    ///
    /// Adapter は <see cref="Message"/> を再解釈しない。
    /// <see cref="Audience"/> と <see cref="PlayerNames"/> のとおりに表示するだけ。
    /// </summary>
    public sealed class AdapterNotification
    {
        public const string AudiencePlayers = "players";
        public const string AudienceServer = "server";

        private readonly List<string> _playerNames;

        public AdapterNotification(string audience, IEnumerable<string> playerNames, string message)
        {
            Audience = audience;
            Message = message;
            _playerNames = playerNames == null ? new List<string>() : new List<string>(playerNames);
        }

        public string Audience { get; private set; }

        public IList<string> PlayerNames
        {
            get { return _playerNames; }
        }

        public string Message { get; private set; }

        public bool IsForServerConsole
        {
            get { return string.Equals(Audience, AudienceServer, StringComparison.Ordinal); }
        }

        public bool IsForPlayers
        {
            get { return string.Equals(Audience, AudiencePlayers, StringComparison.Ordinal); }
        }
    }

    /// <summary>PHP Bridge の notification-only response。</summary>
    public sealed class SnapshotResponse
    {
        private readonly List<AdapterNotification> _notifications;

        public SnapshotResponse(string requestId, string worldKey, IEnumerable<AdapterNotification> notifications)
        {
            RequestId = requestId;
            WorldKey = worldKey;
            _notifications = notifications == null
                ? new List<AdapterNotification>()
                : new List<AdapterNotification>(notifications);
        }

        public string RequestId { get; private set; }

        public string WorldKey { get; private set; }

        public IList<AdapterNotification> Notifications
        {
            get { return _notifications; }
        }

        /// <summary>
        /// 送信した envelope の identity と一致するか。
        ///
        /// contracts/snapshot-response-v1.schema.json は <c>requestId</c> / <c>worldKey</c> を
        /// 必須にしている。これを in-flight の要求と照合せずに通知を表示すると、
        /// 古い応答や別要求の応答で誤った Player へ通知しうる (docs/design.md §6.5)。
        ///
        /// <c>requestId</c> は UUID の16進表記であり、大文字小文字の差は同一とみなす。
        /// <c>worldKey</c> は識別子そのものなので厳密一致で比較する。
        /// </summary>
        public bool Matches(string requestId, string worldKey)
        {
            return string.Equals(RequestId, requestId, StringComparison.OrdinalIgnoreCase)
                && string.Equals(WorldKey, worldKey, StringComparison.Ordinal);
        }
    }

    /// <summary>
    /// response JSON の読み取り。
    ///
    /// **Registry 結果・Achievement Key・Backlog Issue Key は response に含まれない**
    /// (docs/design.md §6.5)。ここでもそれらを読もうとしない。
    /// </summary>
    public static class SnapshotResponseReader
    {
        public static bool TryRead(string json, out SnapshotResponse response, out string error)
        {
            response = null;
            object parsed;

            if (!JsonParser.TryParse(json, out parsed, out error))
            {
                return false;
            }

            var root = parsed as Dictionary<string, object>;

            if (root == null)
            {
                error = "response must be a JSON object.";
                return false;
            }

            // contracts/snapshot-response-v1.schema.json の required は
            // requestId / worldKey / notifications の3つ。欠けた応答は読めたことにしない。
            string requestId;
            string worldKey;

            if (!TryReadRequiredString(root, "requestId", out requestId, out error)
                || !TryReadRequiredString(root, "worldKey", out worldKey, out error))
            {
                return false;
            }

            object rawNotifications;

            if (!root.TryGetValue("notifications", out rawNotifications))
            {
                error = "response is missing 'notifications'.";
                return false;
            }

            var list = rawNotifications as List<object>;

            if (list == null)
            {
                error = "'notifications' must be a JSON array.";
                return false;
            }

            var notifications = new List<AdapterNotification>();

            for (var i = 0; i < list.Count; i++)
            {
                var entry = list[i] as Dictionary<string, object>;

                if (entry == null)
                {
                    error = "each notification must be a JSON object.";
                    return false;
                }

                object rawAudience;
                object rawMessage;

                if (!entry.TryGetValue("audience", out rawAudience) || !(rawAudience is string))
                {
                    error = "notification.audience must be a string.";
                    return false;
                }

                if (!entry.TryGetValue("message", out rawMessage) || !(rawMessage is string)
                    || ((string)rawMessage).Length == 0)
                {
                    error = "notification.message must be a non-empty string.";
                    return false;
                }

                var audience = (string)rawAudience;
                var playerNames = new List<string>();
                object rawPlayerNames;

                if (entry.TryGetValue("playerNames", out rawPlayerNames) && rawPlayerNames != null)
                {
                    var names = rawPlayerNames as List<object>;

                    if (names == null)
                    {
                        error = "notification.playerNames must be a JSON array.";
                        return false;
                    }

                    for (var j = 0; j < names.Count; j++)
                    {
                        var name = names[j] as string;

                        if (name == null || name.Length == 0)
                        {
                            error = "notification.playerNames must contain non-empty strings.";
                            return false;
                        }

                        playerNames.Add(name);
                    }
                }

                notifications.Add(new AdapterNotification(audience, playerNames, (string)rawMessage));
            }

            response = new SnapshotResponse(requestId, worldKey, notifications);

            return true;
        }

        private static bool TryReadRequiredString(
            IDictionary<string, object> root,
            string name,
            out string value,
            out string error)
        {
            object raw;
            value = null;

            if (!root.TryGetValue(name, out raw))
            {
                error = "response is missing '" + name + "'.";

                return false;
            }

            var text = raw as string;

            if (text == null || text.Length == 0)
            {
                error = "response." + name + " must be a non-empty string.";

                return false;
            }

            value = text;
            error = null;

            return true;
        }
    }
}
