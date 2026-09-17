#nullable disable
using System;

namespace TerrariaBacklog.Adapter.Runtime
{
    /// <summary>
    /// periodic reconciliation の発火判定 (docs/design.md §7.4)。
    ///
    /// 既定60秒・設定可能。タイマー thread を持たず、game thread の update から
    /// 現在時刻を渡して判定するだけにする（Terraria state の読み取りを
    /// game thread に閉じるため）。
    /// </summary>
    public sealed class PeriodicTrigger
    {
        private readonly TimeSpan _interval;
        private bool _armed;
        private DateTime _nextDueUtc;

        public PeriodicTrigger(int intervalSeconds)
        {
            if (intervalSeconds <= 0)
            {
                throw new ArgumentOutOfRangeException("intervalSeconds");
            }

            _interval = TimeSpan.FromSeconds(intervalSeconds);
        }

        /// <summary>起動 Snapshot の直後など、次回までの間隔を測り始める。</summary>
        public void Arm(DateTime nowUtc)
        {
            _armed = true;
            _nextDueUtc = nowUtc + _interval;
        }

        /// <summary>
        /// 発火時刻に達していれば true を返し、次回時刻へ進める。
        /// </summary>
        public bool TryTakeDue(DateTime nowUtc)
        {
            if (!_armed)
            {
                return false;
            }

            if (nowUtc < _nextDueUtc)
            {
                return false;
            }

            // 長時間の停止でまとめ打ちしないよう、現在時刻から測り直す。
            _nextDueUtc = nowUtc + _interval;

            return true;
        }
    }
}
