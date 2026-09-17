#nullable disable
using System.Globalization;

namespace TerrariaBacklog.Adapter.Runtime
{
    /// <summary>
    /// World Key の生成 (docs/design.md §5.1, docs/specs/world-identity.md)。
    ///
    /// 既定は <c>terraria:&lt;Main.worldID&gt;</c>。
    /// <c>WorldKeyOverride</c> があればそちらを使う（.wld のコピー運用向け）。
    /// **World 表示名は識別子に使わない。**
    /// </summary>
    public static class WorldKeyFactory
    {
        public const string DefaultPrefix = "terraria:";

        public static string Create(int terrariaWorldId, string worldKeyOverride)
        {
            if (worldKeyOverride != null)
            {
                var trimmed = worldKeyOverride.Trim();

                if (trimmed.Length > 0)
                {
                    return trimmed;
                }
            }

            return DefaultPrefix + terrariaWorldId.ToString(CultureInfo.InvariantCulture);
        }
    }
}
