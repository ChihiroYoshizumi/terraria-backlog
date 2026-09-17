#nullable disable
using System;
using System.Collections.Generic;

namespace TerrariaBacklog.Adapter.Runtime
{
    /// <summary>
    /// 起動時の runtime compatibility gate (docs/design.md §2.3)。
    ///
    /// 対応バージョンの正本は docs/design.md §2.3。ここはビルド時に同梱する
    /// SupportedVersionMatrix であり、runtime が一致しない場合は
    /// **hook 登録も Snapshot 送信も開始しない** (fail closed)。
    /// </summary>
    public static class SupportedRuntimeMatrix
    {
        public const string SupportedTerrariaVersion = "1.3.0.8";
        public const string SupportedTShockVersion = "4.3.13";

        /// <summary>
        /// <c>Terraria.Main.versionNumber</c> は <c>"v1.3.0.8"</c> のように
        /// 先頭へ <c>v</c> が付く。Snapshot には正規化した値を載せる。
        /// </summary>
        public static string NormalizeTerrariaVersion(string raw)
        {
            if (raw == null)
            {
                return string.Empty;
            }

            var value = raw.Trim();

            if (value.Length > 0 && (value[0] == 'v' || value[0] == 'V'))
            {
                value = value.Substring(1);
            }

            return value.Trim();
        }

        /// <summary>
        /// <c>TShockAPI.TShock.VersionNum</c> は <see cref="Version"/> (4.3.13.0)。
        /// Snapshot と突き合わせる値は major.minor.build の3要素に落とす。
        /// </summary>
        public static string NormalizeTShockVersion(int major, int minor, int build)
        {
            return major.ToString(System.Globalization.CultureInfo.InvariantCulture)
                + "." + minor.ToString(System.Globalization.CultureInfo.InvariantCulture)
                + "." + (build < 0 ? 0 : build).ToString(System.Globalization.CultureInfo.InvariantCulture);
        }

        /// <summary>
        /// 対応する組み合わせかどうか。不一致の理由は <paramref name="problems"/> に入る。
        /// </summary>
        public static bool IsSupported(string terrariaVersion, string tshockVersion, out IList<string> problems)
        {
            var found = new List<string>();

            if (!string.Equals(terrariaVersion, SupportedTerrariaVersion, StringComparison.Ordinal))
            {
                found.Add("Terraria " + Describe(terrariaVersion) + " is not supported (expected "
                    + SupportedTerrariaVersion + ").");
            }

            if (!string.Equals(tshockVersion, SupportedTShockVersion, StringComparison.Ordinal))
            {
                found.Add("TShock " + Describe(tshockVersion) + " is not supported (expected "
                    + SupportedTShockVersion + ").");
            }

            problems = found;

            return found.Count == 0;
        }

        private static string Describe(string value)
        {
            return string.IsNullOrEmpty(value) ? "<unknown>" : value;
        }
    }
}
