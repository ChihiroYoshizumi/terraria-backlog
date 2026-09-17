#nullable disable
using System;
using System.IO;
using System.Text;

namespace TerrariaBacklog.Adapter.Configuration
{
    /// <summary>
    /// 設定ファイルの読み書き (docs/design.md §7.1)。
    ///
    /// 永続 Queue / Outbox ではなく「設定」だけを保存する。Snapshot・ACK context・
    /// 復旧待ちフラグはここへ書かない (docs/design.md §7.6)。
    /// </summary>
    public static class AdapterSettingsFile
    {
        public const string FileName = "TerrariaBacklog.Adapter.json";

        /// <summary>
        /// 設定ファイルに平文で置きたくない運用のための上書き経路。
        /// 環境変数が設定されていればファイルの値より優先する。
        /// </summary>
        public const string TokenEnvironmentVariable = "TERRARIA_BACKLOG_ADAPTER_TOKEN";

        /// <summary>
        /// 設定を読む。ファイルが無ければ既定値で新規作成する（Token は空のまま）。
        /// </summary>
        public static AdapterSettings LoadOrCreate(string directory, out string error)
        {
            error = null;

            var path = Path.Combine(directory, FileName);

            AdapterSettings settings;

            if (!File.Exists(path))
            {
                settings = new AdapterSettings();

                try
                {
                    if (!Directory.Exists(directory))
                    {
                        Directory.CreateDirectory(directory);
                    }

                    File.WriteAllText(path, settings.ToJson(true), new UTF8Encoding(false));
                }
                catch (Exception ex)
                {
                    error = "failed to create " + path + ": " + ex.Message;
                    return null;
                }
            }
            else
            {
                string json;

                try
                {
                    json = File.ReadAllText(path, Encoding.UTF8);
                }
                catch (Exception ex)
                {
                    error = "failed to read " + path + ": " + ex.Message;
                    return null;
                }

                settings = AdapterSettings.FromJson(json, out error);

                if (settings == null)
                {
                    error = path + " is not usable: " + error;
                    return null;
                }
            }

            ApplyEnvironmentOverrides(settings);

            return settings;
        }

        /// <summary>環境変数による上書き。Token の値そのものはログへ出さない。</summary>
        public static void ApplyEnvironmentOverrides(AdapterSettings settings)
        {
            if (settings == null)
            {
                return;
            }

            string token = null;

            try
            {
                token = Environment.GetEnvironmentVariable(TokenEnvironmentVariable);
            }
            catch (Exception)
            {
                // 環境変数を読めない実行環境でも設定ファイル側の値で動けるようにする。
            }

            if (!string.IsNullOrEmpty(token) && token.Trim().Length > 0)
            {
                settings.AdapterToken = token.Trim();
            }
        }
    }
}
