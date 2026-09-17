# Task 06: Mapping Repository と課題同期を実装する

## 目的
Backlog の攻略課題 Mapping を安全に読み取り、達成済み Registry に対応する未完了課題だけを完了させる。

## 依存関係
- Task 03 完了
- Task 05 完了

## 参照
- `docs/spec.md` §5.3, §8, AC-06, AC-07, AC-10, AC-14, AC-15, AC-19
- `docs/specs/backlog-mapping.md`
- `docs/specs/backlog-sync.md`
- `docs/design.md` §11, §12, §20, §21

## 対象 AC
- AC-06
- AC-07
- AC-10
- AC-14
- AC-15
- AC-19

## 実装すること

### 1. MappingIssue / MappingIndex を実装する
攻略課題用の read model を作る。

必要情報:
- Project ID
- Issue ID / Issue Key
- 完了状態
- Terraria Record Type
- Terraria World Key
- Terraria Key

Snapshot 単位で:

```text
mappingIndex[achievement_key] = MappingIssue[]
```

を構築する。

### 2. Mapping scan を実装する
Backlog から対象 Project / World の未完了 Issue を全ページ取得し、PHP 側で以下を判定する。

有効 Mapping:
- Project ID exact match
- `Terraria Record Type` が null / 空文字
- 未完了
- World Key が設定済み
- Terraria Key が設定済み

除外/診断:
- `Record Type === registry` -> Mapping から除外
- unknown non-empty Record Type -> ignore + diagnostic log
- World Key / Terraria Key の片方だけ -> invalid mapping として ignore + log
- Mapping 無しの一般/研修課題 -> silent ignore
- 完了済み一般/攻略課題 -> scan 対象から除外し、変更しない

### 3. Registry completed set と join する
Task 05 が返す完了済み Registry set と `mappingIndex` をメモリ上で照合する。

- Registry 未達成なら課題を変更しない。
- Registry 達成済みなら同一 Achievement に紐付く未完了課題をすべて完了候補にする。
- 1 Achievement -> 複数 Issue を許可する。

### 4. Mapping issue 完了処理を実装する
PATCH 前に必ず current issue を再取得し、以下を再確認する。
- Project ID exact match
- 現在も未完了
- Record Type が null / empty
- World Key exact match
- Terraria Key exact match

条件を満たす場合のみ configured Done Status へ更新する。

- 再取得時点で完了済みなら PATCH しない。
- Mapping が解除/変更されていたら更新しない。
- update failure は success 扱いしない。

### 5. reopen 対応を実装する
利用者が達成済み課題を reopen した場合、次回 reconciliation scan では未完了 Mapping として再度取得されるため、対応 Registry が存在すれば再完了できるようにする。

### 6. post-hoc Mapping を成立させる
Registry 登録後に Item が chest から消えていても、Registry が残っていれば後から Mapping された課題を完了できるようにする。

この Task では reconciliation trigger 自体は Task 07 に任せるが、`loadIncompleteIndex()` を呼び直せば新しい Mapping を検出できる Repository にする。

## 想定変更ファイル
- `bridge/app/Infrastructure/Backlog/MappingRepository*`
- `bridge/app/Application/SynchronizeMappedIssues*`
- mapping read model / index
- `bridge/tests/Unit/**`
- `bridge/tests/Feature/**`

## 実装しないこと
- periodic/manual trigger
- player ACK
- TShock Adapter
- Mapping の自動作成/補完

## テスト
- Registry 未達成 Mapping は更新しない
- Registry 達成後のみ完了
- same Achievement -> multiple issues を全て完了
- mapping-less issue ignored
- partial Mapping ignored + log
- unknown non-empty Record Type ignored + log
- completed general/training issue ignored
- Mapping 解除後は更新しない
- Project/World/Key mismatch を更新しない
- reopened issue を次回呼び出しで再完了
- post-hoc Mapping を再 scan で検出
- Snapshot 内複数 Achievement でも full Mapping scan を Achievement ごとに繰り返さない

## 完了条件
- 一般課題を推測で更新しない。
- AC-06/07/10/14/15/19 の Mapping 要件がテストで追跡できる。
- Task 07 から scan + sync を呼べば reconciliation が成立する。