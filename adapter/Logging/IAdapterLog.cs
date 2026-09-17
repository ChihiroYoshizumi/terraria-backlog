#nullable disable
namespace TerrariaBacklog.Adapter.Logging
{
    /// <summary>
    /// Adapter のログ出力口。TShock の <c>ILog</c> へ薄く委譲する実装を注入する。
    ///
    /// docs/spec.md §10: **Adapter Token / Backlog API Key 等の秘密情報をログへ出さない。**
    /// 呼び出し側でメッセージに秘密情報を含めないこと。
    /// </summary>
    public interface IAdapterLog
    {
        void Info(string message);

        void Warn(string message);

        void Error(string message);
    }

    /// <summary>テストや log 未設定時に使う no-op 実装。</summary>
    public sealed class NullAdapterLog : IAdapterLog
    {
        public static readonly NullAdapterLog Instance = new NullAdapterLog();

        private NullAdapterLog()
        {
        }

        public void Info(string message)
        {
        }

        public void Warn(string message)
        {
        }

        public void Error(string message)
        {
        }
    }
}
