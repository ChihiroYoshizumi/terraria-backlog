# Task 05: Achievement Registry Repository と冪等性を実装する

## 目的
Backlog を Achievement Registry として安全に使い、重複・timeout・並行実行でも誤登録や誤 ACK の原因を作らない。

## 依存関係
- Task 03 完了
- Task 04 完了

## 参照
- `docs/spec.md` §5.2, §8, AC-03, AC-05, AC-11, AC-12, AC-13
- `docs/specs/achievement-registry.md`
- `docs/design.md` §10, §20, §21

## 対象 AC
- AC-03
- AC-05
- AC-11
- AC-12
- AC-13

## 実装すること

### 1. RegistryIssue / RegistryIndex を実装する
Backlog Issue を直接 application 層へ漏らしすぎないよう、Registry 用 read model を作る。

必要情報:
- Backlog Project ID
- Issue ID / Issue Key
- Record Type
- Terraria World Key
- Terraria Key
- 完了状態

Snapshot 単位で:

```text
registryIndex[achievement_key] = RegistryIssue[]
```

を構築する。

### 2. Registry scan を実装する
- Backlog Issue List を対象 `TRAINING_YOSHIZUMI` Project ID に限定する。
- 全ページ取得する。
- PHP 側の final match でも以下を完全一致確認する。
  - Project ID
  - `Terraria Record Type === registry`
  - World Key
  - Terraria Key
- API 検索文字列だけを exact lookup とみなさない。
- scan failure を0件扱いしない。

### 3. `ensureRegistered` を実装する
`docs/design.md` §10.4 の分岐をそのままコード化する。

#### exact Registry 0件
1. lock 取得
2. lock 内でもう一度 latest exact Registry を確認
3. 無ければ Registry Issue 作成
4. Done Status へ更新
5. GET または限定再検索で保存確認
6. index 更新
7. `registered` を返す

#### exact Registry 1件
- 完了済み -> `already_registered`
- 未完了 -> Done 更新 -> 再取得確認 -> `registered`

#### exact Registry 複数
- 完了済み1件以上 -> logical `already_registered`
- 完了済み0件 -> fail closed
  - 新規作成しない
  - 既存Issueを自動完了しない
  - warning/log
  - `failed`

### 4. create/update の不明応答を扱う
timeout 等で結果不明の場合:
- 即座に create をやり直さない。
- 対象 World Key + Achievement Key を再検索する。
- 既存 Registry が確認できたらその状態から継続する。
- 確認不能なら success と断定しない。

### 5. cross-process file lock を実装する
`world_key + achievement_key` 単位で shared OS file lock を使う。

例:

```text
/var/lock/terraria-backlog/<sha256(world_key + achievement_key)>.lock
```

lock 範囲:

```text
latest exact lookup
 -> create/update
 -> verify
```

まで全体。

- 同一ホスト全 PHP worker で共有する。
- in-memory mutex だけで済ませない。
- 複数ホスト対応は追加しない。

### 6. Registry Issue 作成 payload を実装する
- Project は fixed/validated ProjectConfiguration から取得
- Record Type = `registry`
- World Key = 対象 world
- Terraria Key = Achievement Key
- 件名は人間が識別できる達成名
- status/issueType/priority は validated config を使用
- Adapter payload から Project/Field ID を受け取らない

## 想定変更ファイル
- `bridge/app/Infrastructure/Backlog/RegistryRepository*`
- `bridge/app/Domain/Registry*` または application read model
- lock helper
- `bridge/tests/Unit/**`
- `bridge/tests/Feature/**`

## 実装しないこと
- Mapping 課題完了
- ACK 文言生成
- 物理重複 Registry の削除・マージ
- distributed lock

## テスト
- none -> create -> done -> verify
- existing done -> no write
- existing incomplete -> repair
- create timeout -> re-search
- update timeout -> re-search/verify
- duplicate incomplete -> no write + failed
- duplicate with completed -> logical success、cleanupしない
- Project ID exact match
- World A の Registry が World B に混ざらない
- 並行リクエストが shared file lock で直列化される
- Snapshot 内複数Achievementでも full Registry scanをAchievementごとに繰り返さない

## 完了条件
- Registry 保存確認前に success 扱いしない。
- AC-11/12 の冪等性・不明状態 fail-closed がテストで担保される。
- Task 07 が RegistryResult を見て通知可否を判断できる。