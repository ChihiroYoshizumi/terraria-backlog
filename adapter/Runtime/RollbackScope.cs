#nullable disable
using System;
using System.Collections.Generic;
using TerrariaBacklog.Adapter.Logging;

namespace TerrariaBacklog.Adapter.Runtime
{
    /// <summary>
    /// 「登録したものを必ず元に戻す」ための undo スタック。
    ///
    /// Plugin の初期化は hook 登録・command 登録・worker thread 起動を順に行う。
    /// 途中で失敗したときに部分的な登録が残ると、無効化したはずの Adapter の
    /// callback がサーバー稼働中ずっと呼ばれ続ける。初期化の各段階で undo を積み、
    /// 失敗時は逆順に巻き戻す (docs/design.md §18.4 の fail closed)。
    ///
    /// teardown も同じスタックを使う。undo 側で例外が出ても残りの undo は実行し、
    /// 例外は外へ出さない（サーバー停止経路を壊さない）。
    ///
    /// TShock に依存しないため、通常の unit test の対象になる。
    /// </summary>
    public sealed class RollbackScope
    {
        private readonly List<Action> _undo = new List<Action>();
        private readonly object _gate = new object();
        private readonly IAdapterLog _log;

        public RollbackScope()
            : this(null)
        {
        }

        public RollbackScope(IAdapterLog log)
        {
            _log = log ?? NullAdapterLog.Instance;
        }

        /// <summary>巻き戻し待ちの件数。</summary>
        public int Count
        {
            get
            {
                lock (_gate)
                {
                    return _undo.Count;
                }
            }
        }

        /// <summary>直前に成功した段階の undo を積む。</summary>
        public void Add(Action undo)
        {
            if (undo == null)
            {
                throw new ArgumentNullException("undo");
            }

            lock (_gate)
            {
                _undo.Add(undo);
            }
        }

        /// <summary>
        /// 積んである undo を逆順に実行し、スタックを空にする。
        /// 2回呼んでも同じ undo を2度実行しない。
        /// </summary>
        public void Rollback()
        {
            List<Action> pending;

            lock (_gate)
            {
                if (_undo.Count == 0)
                {
                    return;
                }

                pending = new List<Action>(_undo);
                _undo.Clear();
            }

            for (var i = pending.Count - 1; i >= 0; i--)
            {
                try
                {
                    pending[i]();
                }
                catch (Exception ex)
                {
                    // 1つの undo の失敗で残りを止めない。
                    _log.Error("rollback step failed: " + ex.Message);
                }
            }
        }
    }
}
