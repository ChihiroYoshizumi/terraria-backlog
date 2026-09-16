# 機能仕様一覧

[最上位仕様](../spec.md)を機能単位で具体化した概要仕様。目的・振る舞い・例外・受け入れ条件を整理し、詳細設計の入力とする。

## 文書の位置付け

- 正本は `docs/spec.md`。本ディレクトリに複製・移動しない。
- 各機能仕様はレビュー中の `draft`。上位仕様の変更提案は既存要件と分けて扱う。
- PHP 主実装、薄い C# Adapter、アプリ専用 DB なし、Backlog 永続化の共通方針は上位仕様を参照する。
- API 形式、クラス構成、ライブラリ選定、数値設定は詳細設計で決める。未決事項は各文書の末尾に記す。
- 今回の対象は [TRAINING_YOSHIZUMI](https://fusic.backlog.jp/projects/TRAINING_YOSHIZUMI)。完了済み攻略・研修課題と Mapping のない一般課題は無視する。完了済み Registry は達成記録として参照する。
- spec-driven-dev の後続工程では標準配置の `docs/specs/spec.md` ではなく、`docs/spec.md` と本索引を入力として指定する。

## 機能一覧と読む順序

| 順序 | 文書 | 責務 |
| --- | --- | --- |
| 1 | [ワールド識別](world-identity.md) | ワールドの境界と安定したキー |
| 2 | [Achievement Registry](achievement-registry.md) | 達成事実の永続化と重複の扱い |
| 3 | [Collection Chest](collection-chest.md) | 納品判定とゲーム内 ACK |
| 4 | [World Progress](world-progress.md) | 永続フラグに基づく進行判定 |
| 5 | [Backlog Mapping](backlog-mapping.md) | 達成と攻略課題の対応 |
| 6 | [Backlog 同期](backlog-sync.md) | 対応課題の完了更新 |
| 7 | [Reconciliation](reconciliation.md) | 再取得・照合による復旧 |
| 8 | [運用・セキュリティ](operations-security.md) | 起動、認証、診断、実行上の制約 |

## 用語と責務の境界

| 用語 | 意味 |
| --- | --- |
| 観測 | Adapter が取得したゲーム側の現在状態。永続化済みとは限らない |
| Achievement | ワールド単位の達成事実。論理キーは `(world_key, achievement_key)` |
| Registry | Backlog に保存した達成事実の正本 |
| Mapping | 攻略課題の属性に保存したワールド・Achievement との対応 |
| ACK | Registry の保存を確認した通知。攻略課題の完了通知とは別 |
| Reconciliation | 現在状態・Registry・Mapping を再照合して不足を補う処理 |

納品・進行判定は達成候補を Registry に渡す。Registry は保存結果を返し、Collection Chest が ACK の表示を担当する。Mapping は対応を定義し、Backlog 同期が課題を更新する。Reconciliation は各機能を再実行する契機を管理し、独自の達成ルールを持たない。

## 上位受け入れ条件の対応

各機能の成功基準は上位 AC を具体化する。同じ AC に複数機能が関与する場合は、主担当と連携確認先を区別する。

| 上位 AC | 主担当 | 連携確認先 |
| --- | --- | --- |
| AC-01 | operations-security | collection-chest、backlog-sync |
| AC-02 | achievement-registry | backlog-mapping、operations-security |
| AC-03 | collection-chest | achievement-registry |
| AC-04 | collection-chest | — |
| AC-05 | collection-chest | achievement-registry、backlog-sync |
| AC-06 | backlog-mapping | achievement-registry、reconciliation |
| AC-07 | backlog-mapping | backlog-sync |
| AC-08 | world-progress | reconciliation |
| AC-09 | collection-chest | reconciliation |
| AC-10 | backlog-sync | reconciliation |
| AC-11 | achievement-registry | backlog-sync |
| AC-12 | achievement-registry | collection-chest |
| AC-13 | world-identity | backlog-mapping、backlog-sync |
| AC-14 | backlog-mapping | backlog-sync |
| AC-15 | backlog-mapping | backlog-sync |
| AC-16 | operations-security | — |
| AC-17 | reconciliation | collection-chest、world-progress |
| AC-18 | operations-security | reconciliation |
| AC-19 | backlog-mapping | backlog-sync、achievement-registry |

## 後続工程への引き継ぎ

1. 機能仕様をレビューし、上位仕様との矛盾と未決事項を確認する。
2. `design` で採用バージョン、状態取得、Backlog 属性・検索、認証・通信、再同期の実行方式を確定する。
3. `split-tasks` で機能仕様と AC を参照する実装タスクへ分割する。

上位 AC の識別子は維持する。Boss の再判定可否など、調査結果によって対象変更が必要な場合は上位仕様の変更としてレビューする。
