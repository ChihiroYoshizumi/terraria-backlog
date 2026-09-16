# Terraria × Backlog ワールド進捗管理システム 詳細設計

状態: draft  
更新日: 2026-09-16

本書は [最上位仕様](spec.md) と [機能仕様](specs/README.md) を入力とする design 文書である。仕様で定義された振る舞いを変更せず、実装に必要な技術選定、責務分割、通信契約、Backlog 上の論理データモデル、再照合方式、エラー処理、テスト方針を確定する。

## 1. 設計原則

1. PHP を主実装とする。
2. Terraria クライアントは Vanilla のままとし、TShock Dedicated Server のみを拡張する。
3. C# Adapter はゲーム状態の観測と PHP への転送、PHP から返された通知の表示だけを担当する。
4. 達成ルール、Achievement Key、Backlog Registry、Mapping、課題同期は PHP に集約する。
5. アプリケーション専用 DB、永続キュー、Outbox は持たない。
6. Backlog を Achievement Registry と Mapping の永続ストレージとして扱う。
7. 復旧可能性は「現在の Terraria 状態」または「Backlog に保存済みの Registry」から再構成できる範囲に限定する。
8. Backlog 障害や PHP 障害で Terraria のゲームループを待たせない。
9. Backlog への書き込みは冪等になるよう、書き込み前後に現在状態を確認する。
10. 不明な状態を成功として扱わない。推測で Registry や課題を更新しない。

---

## 2. 技術選定

### 2.1 PHP Bridge

| 項目 | 採用 |
| --- | --- |
| PHP | PHP 8.5 |
| Framework | Laravel 13 |
| HTTP Client | Laravel HTTP Client |
| 永続 DB | 使用しない |
| Queue | 永続 Queue は使用しない |
| Session | 使用しない |
| Cache | 正本にしない。必要な場合のみ消失可能なメモリキャッシュを利用 |
| Lock | 同一ホスト上の全 PHP Worker で共有する OS file lock を利用 |
| Logging | Laravel logging。構造化されたコンテキストを付与 |
| Test | Pest / PHPUnit、HTTP fake |

Laravel 13 を API と CLI の実行基盤として利用するが、Eloquent、Migration、DB Queue、DB Session はシステム成立に必要としない。

MVP では以下を前提とする。

```env
QUEUE_CONNECTION=sync
SESSION_DRIVER=array
CACHE_STORE=array
```

これらは再起動時に失われてもよい情報にのみ利用する。

### 2.2 TShock Adapter

| 項目 | 採用 |
| --- | --- |
| 言語 | C# |
| Runtime | 採用 TShock が要求する .NET Runtime |
| 実装形態 | TShock Plugin |
| 責務 | World State / Chest State の Snapshot 作成、PHP への非同期送信、ゲーム内 ACK 表示 |
| Backlog API | 呼ばない |
| Achievement 判定 | 行わない |
| 永続化 | 行わない |

### 2.3 バージョン互換性 Gate

Terraria と TShock は対応バージョンが一致していることを起動・導入の前提とする。

2026-09-16 時点では Terraria Desktop の最新リリースと TShock stable の対応 Terraria バージョンに差があるため、設計上「最新 Terraria なら必ず動作する」とは扱わない。

MVP では次の3段階で fail closed にする。

1. Adapter 起動時に runtime から Terraria version と TShock version を取得する。
2. Adapter がビルド時に同梱した `SupportedVersionMatrix` と runtime の組み合わせを照合し、一致しない場合は Hook 登録・Snapshot 送信を開始しない。
3. Snapshot に runtime version を含め、PHP も `TERRARIA_SUPPORTED_RUNTIME` 設定と一致しない Snapshot を `409` で拒否する。

Snapshot で送る例:

```json
{
  "runtime": {
    "adapterVersion": "0.1.0",
    "tshockVersion": "6.1.0",
    "terrariaVersion": "1.4.5.6"
  }
}
```

PHP 設定例:

```env
TERRARIA_SUPPORTED_RUNTIME=6.1.0:1.4.5.6
```

`php artisan terraria:doctor` はこの設定が空でないこと、実装が認識する組み合わせであることを検証する。実際のサーバー runtime は Adapter 起動 Gate と各 Snapshot の runtime validation で検証する。

未対応の組み合わせでは運用を開始しない。将来 TShock stable が新しい Terraria バージョンへ対応したら、Adapter のビルドと Smoke Test を通した上で `SupportedVersionMatrix` と PHP 設定を更新する。

参考:

- TShock Releases: https://github.com/Pryaxis/TShock/releases
- Terraria Desktop versions: https://terraria.wiki.gg/wiki/Desktop_version_history

---

## 3. 全体アーキテクチャ

```text
Vanilla Terraria Clients
          |
          v
TShock Dedicated Server
          |
          | Terraria API / TShock hooks
          v
TerrariaBacklog.Adapter (C#)
  - Runtime compatibility gate
  - World Snapshot
  - Collection Chest Snapshot
  - Debounce / ACK recipient aggregation
  - 非同期 HTTP Sender
  - ACK Renderer
          |
          | POST /api/v1/worlds/{worldKey}/snapshots
          v
Terraria Backlog Bridge (Laravel / PHP)
  - Request Validation
  - Achievement Evaluation
  - Snapshot-scoped Registry / Mapping index
  - Registry Repository
  - Backlog Sync
  - Reconciliation
          |
          | Backlog API v2 / HTTPS
          v
TRAINING_YOSHIZUMI
  - Registry 課題
  - 攻略課題 + Mapping
```

### 3.1 責務境界

#### Adapter が知ってよいもの

- Terraria native world ID
- Terraria / TShock runtime version
- World の現在状態を表す生フラグ
- Chest ID / 座標 / 名前 / Item ID / Stack
- Collection Chest の設定名
- 通知対象プレイヤー
- PHP Bridge URL
- Adapter 認証 Token

#### Adapter が知らないもの

- `boss:moon_lord` 等の Achievement Key
- Backlog Project Key
- Backlog API Key
- Backlog Custom Field ID
- Backlog Issue Key
- Backlog の完了 Status ID

#### PHP が知るもの

- 生 World State と Achievement Key の対応
- 対応 Terraria version の Item catalog
- Backlog Registry の識別ルール
- Mapping のカスタム属性
- 対象 Project / Status / Issue Type / Priority
- 冪等処理・再試行ルール

---

## 4. リポジトリ構成

実装フェーズでは次の構成を基本とする。

```text
terraria-backlog/
├── docs/
│   ├── spec.md
│   ├── design.md
│   └── specs/
├── bridge/
│   ├── app/
│   │   ├── Application/
│   │   ├── Domain/
│   │   ├── Infrastructure/
│   │   │   └── Backlog/
│   │   └── Http/
│   ├── config/
│   ├── routes/
│   └── tests/
├── adapter/
│   ├── TerrariaBacklog.Adapter.csproj
│   ├── Hooks/
│   ├── Snapshots/
│   ├── Transport/
│   └── Tests/
├── contracts/
│   ├── snapshot-v1.schema.json
│   ├── examples/
│   └── terraria/
│       └── <version>/items.json
└── scripts/
```

`contracts` は Adapter と PHP の境界仕様を共有するための正本とする。`contracts/terraria/<version>/items.json` は採用 Terraria version の Item ID と `maxStack` を保持する version-pinned catalog とし、PHP の入力検証に利用する。

---

## 5. ワールド識別

### 5.1 World Key

Adapter は Terraria の `Main.worldID` を native ID として取得できる。TShock 本体も `Main.worldID` をワールドごとの識別に利用しているため、通常のワールドではこれを利用する。

既定の `world_key` は次の形式とする。

```text
terraria:<Main.worldID>
```

例:

```text
terraria:123456789
```

ただし `.wld` のコピーは native world ID を引き継ぐ可能性があるため、コピーを別ワールドとして運用する場合は Adapter 設定の `WorldKeyOverride` を必須とする。

```json
{
  "WorldKeyOverride": "terraria:fusic-multiplayer-2026"
}
```

### 5.2 PHP 側の許可

PHP は受信 payload の値から任意のワールドを信用しない。

環境変数または設定で許可する world key を明示する。

```env
TERRARIA_ALLOWED_WORLD_KEYS=terraria:123456789
```

リクエスト URL、payload の `world.key`、許可リストの3つが一致しない場合は `403` とする。

---

## 6. Adapter → PHP Snapshot Contract

### 6.1 Endpoint

```http
POST /api/v1/worlds/{worldKey}/snapshots
Authorization: Bearer <adapter-token>
Content-Type: application/json
```

Adapter Token は Backlog API Key と別の秘密情報とする。

### 6.2 Request

```json
{
  "schemaVersion": 1,
  "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
  "reason": "collection_change",
  "observedAt": "2026-09-16T10:00:00+09:00",
  "runtime": {
    "adapterVersion": "0.1.0",
    "tshockVersion": "6.1.0",
    "terrariaVersion": "1.4.5.6"
  },
  "world": {
    "key": "terraria:123456789",
    "terrariaWorldId": 123456789,
    "name": "Fusic World"
  },
  "collectionChestName": "BACKLOG_COLLECTION",
  "flags": {
    "downedBoss1": true,
    "downedBoss3": true,
    "hardMode": false,
    "downedMechBoss1": false,
    "downedMechBoss2": false,
    "downedMechBoss3": false,
    "downedPlantBoss": false,
    "downedGolemBoss": false,
    "downedAncientCultist": false,
    "downedMoonlord": false
  },
  "collectionChests": [
    {
      "x": 120,
      "y": 340,
      "name": "BACKLOG_COLLECTION",
      "items": [
        {
          "type": 1326,
          "stack": 1,
          "name": "Rod of Discord"
        }
      ]
    }
  ],
  "trigger": {
    "playerNames": ["player1"]
  }
}
```

### 6.3 `reason`

以下のみ許可する。

- `startup`
- `periodic`
- `collection_change`
- `world_change`
- `manual`

PHP の達成判定は `reason` に依存させない。`reason` は診断、ACK 表示、観測契機の把握にのみ利用する。

### 6.4 Validation

PHP は最低限以下を検証する。

- `schemaVersion === 1`
- `requestId` は UUID
- `runtime.tshockVersion` と `runtime.terrariaVersion` が設定済み supported pair と完全一致
- `world.key` が URL と一致
- `world.key` が allowlist に存在
- `terrariaWorldId` が整数
- `collectionChestName` が PHP 設定の `TERRARIA_COLLECTION_CHEST_NAME` と一致
- `collectionChests[].name` が `collectionChestName` と一致
- `flags` は定義済み boolean field のみ利用
- `collectionChests[].items[].type` は JSON integer で、採用 Terraria version の Item catalog に存在する ID
- `collectionChests[].items[].stack` は JSON integer で `1 <= stack <= catalog[type].maxStack`
- Chest / Item 件数に上限を設定
- Request Body のサイズ上限を設定
- 任意 URL、Project Key、Backlog Issue Key を Adapter から受け取らない

PHP の型 coercion に依存せず、schema validation と request validation の両方で integer を要求する。小数、数値文字列、catalog にない Item ID、Item ごとの `maxStack` を超える値は `422` とし、その Item から Registry を作成しない。

未知の field は将来互換のため読み飛ばしてよいが、未知の `schemaVersion` は `422` とする。

### 6.5 Response

```json
{
  "requestId": "0199f136-9e36-7f41-b148-e5b4f384a321",
  "worldKey": "terraria:123456789",
  "registry": [
    {
      "achievementKey": "item:1326",
      "result": "registered"
    },
    {
      "achievementKey": "boss:eye_of_cthulhu",
      "result": "already_registered"
    }
  ],
  "sync": {
    "completed": ["TRAINING_YOSHIZUMI-123"],
    "unchanged": [],
    "failed": []
  }
}
```

`registry[].result` は以下とする。

- `registered`
- `already_registered`
- `failed`

Collection Chest の成功 ACK は `registered` または `already_registered` が確認できた Item にだけ表示する。

---

## 7. Adapter のイベント処理

### 7.1 Adapter 設定

Adapter 設定には少なくとも次を持つ。

```json
{
  "BridgeUrl": "http://127.0.0.1:8080",
  "WorldKeyOverride": null,
  "CollectionChestName": "BACKLOG_COLLECTION",
  "ReconciliationIntervalSeconds": 60
}
```

`CollectionChestName` は設定可能で、既定値だけが `BACKLOG_COLLECTION` である。起動スキャン、変更イベントの対象判定、Quick Stack 後の再取得、periodic reconciliation のすべてでこの設定値を使う。固定文字列を監視条件に埋め込まない。

### 7.2 起動時

`GamePostInitialize` 相当のワールド初期化完了後に compatibility gate を通し、Full Snapshot を作成して PHP へ送る。

起動 Snapshot には、

- runtime version
- World identity
- 対象 World State 全項目
- `CollectionChestName` と完全一致する全 Collection Chest の現在内容

を含める。

### 7.3 Collection Chest

TShock の `GetDataHandlers.ChestItemChange` を変更契機として利用する。

Quick Stack は通常の Slot Change と別経路になる可能性があり、TShock 本体でも `OTAPI.Hooks.Chest.QuickStack` が別途扱われている。このため Adapter は両方を「設定された Collection Chest の状態が汚れた可能性がある」という trigger として扱う。

重要なのは、イベント引数の差分を Achievement とみなさないことである。

```text
Chest change / Quick Stack
        |
        v
設定名と一致する Chest を dirty とする
        |
        v
約 500ms debounce
        |
        v
サーバー状態から Chest 全スロットを再取得
        |
        v
最新 Snapshot を送信
```

同じ debounce window 内で複数 Player が対象 Chest を変更した場合、`trigger.playerNames` に重複排除して集約する。

### 7.4 Periodic Reconciliation

イベント取りこぼし、PHP 再起動、後付け Mapping を補完するため、Adapter は60秒ごとに Full Snapshot を送る。

60秒は設計上の既定値であり設定可能とする。

Periodic Snapshot は ACK recipient を持たず、Collection Change 起点の未送信 Snapshot を置き換えてはならない。

### 7.5 Manual Sync

TShock に管理者向けコマンドを追加する。

```text
/backlog sync
```

必要 Permission:

```text
terrariabacklog.sync
```

コマンドは Full Snapshot の即時送信を要求するだけで、Backlog の仕様や Mapping を C# 側で扱わない。

### 7.6 ゲームループを待たせない / Snapshot coalescing

Terraria API / Chest state の読み取りは安全なゲームスレッド上で Snapshot DTO にコピーする。HTTP はその DTO をバックグラウンドで送る。

- ゲームスレッドで Backlog/PHP 通信を待たない。
- HTTP 送信は single-flight とし、同時に複数本走らせない。
- `periodic` / `world_change` / `startup` の状態 Snapshot は、未送信同士なら最新状態へ coalesce してよい。
- `collection_change` Snapshot は ACK routing 情報を持つため periodic 等で破棄しない。
- Collection Change が連続する場合、最新 Chest state に更新しつつ `trigger.playerNames` を集合として保持・集約する。
- Pending collection trigger は送信成功または失敗応答を受けるまで保持する。プロセス終了では失われてよい。
- メモリ上の未送信 Snapshot はプロセス終了で失われてよく、状態そのものは次回 reconciliation で再取得する。

永続 Queue は作らない。プロセス終了で ACK routing 情報を失った場合、達成自体は再照合できても過去の操作 Player への ACK 再現は保証しない。

---

## 8. Achievement Evaluation

Adapter は raw state を送るだけとし、PHP が Achievement Key を決定する。

### 8.1 MVP の World Progress 対応表

| Raw state | Achievement Key | MVP | 理由 |
| --- | --- | --- | --- |
| `downedBoss1` | `boss:eye_of_cthulhu` | 対象 | 永続 state から再判定可能 |
| `downedBoss3` | `boss:skeletron` | 対象 | 永続 state から再判定可能 |
| `hardMode` | `world:hardmode` | 対象 | 永続 state から再判定可能 |
| `downedMechBoss1` | `boss:the_destroyer` | 対象 | 永続 state から再判定可能 |
| `downedMechBoss2` | `boss:the_twins` | 対象 | 永続 state から再判定可能 |
| `downedMechBoss3` | `boss:skeletron_prime` | 対象 | 永続 state から再判定可能 |
| `downedPlantBoss` | `boss:plantera` | 対象 | 永続 state から再判定可能 |
| `downedGolemBoss` | `boss:golem` | 対象 | 永続 state から再判定可能 |
| `downedAncientCultist` | `boss:lunatic_cultist` | 対象 | 永続 state から再判定可能 |
| `downedMoonlord` | `boss:moon_lord` | 対象 | 永続 state から再判定可能 |

### 8.2 MVP で独立 Achievement にしないもの

#### Eater of Worlds / Brain of Cthulhu

Terraria の `downedBoss2` は Eater of Worlds と Brain of Cthulhu を独立して表さないため、どちらを倒したかを推測しない。MVP の個別 Achievement にはしない。

#### Wall of Flesh

MVP では `world:hardmode` を再判定可能な進行として扱う。Wall of Flesh 撃破を独立して証明する永続 state を確認できない構成では、`boss:wall_of_flesh` を `hardMode` から推測して登録しない。

Wall of Flesh の撃破イベントそのものを一過性イベントとして捕捉して永続保存する方式は、本仕様の非スコープである。

### 8.3 Item Achievement

Collection Chest の Item は §6.4 の version-pinned Item catalog validation を通過したものだけを評価する。

```text
catalog.contains(item.type)
&& item.stack is integer
&& 1 <= item.stack <= catalog[item.type].maxStack
```

を満たす Item ごとに、

```text
item:<item.type>
```

を生成する。同一 Item が複数 Chest / Slot にあっても1つの Achievement として扱う。

---

## 9. Backlog を仮想 DB として扱う設計

### 9.1 対象 Project

MVP は既存の Backlog Project:

```text
TRAINING_YOSHIZUMI
```

のみを操作する。Adapter から Project Key を指定させない。

`BACKLOG_PROJECT_KEY` は可変な一般設定ではなく、MVP の safety assertion として `TRAINING_YOSHIZUMI` 以外を許可しない。

### 9.2 必要な Custom Field

Text 型 Custom Field を3つ必要とする。

| 表示名 | 用途 | Registry | 攻略課題 |
| --- | --- | --- | --- |
| `Terraria Record Type` | Registry と一般課題の区別 | `registry` | 空欄 |
| `Terraria World Key` | World Mapping | 必須 | Mapping 時必須 |
| `Terraria Key` | Achievement Mapping | 必須 | Mapping 時必須 |

`Achievement Type` 専用 field は作らず、`Terraria Key` の prefix から導出する。

Custom Field の作成は Project Admin 権限が必要になるため、MVP の PHP アプリケーションが自動作成しない。導入時に Backlog 上で作成し、Custom Field ID を PHP 設定へ渡す。

### 9.3 PHP 設定

例:

```env
BACKLOG_BASE_URL=https://fusic.backlog.jp
BACKLOG_API_KEY=***
BACKLOG_PROJECT_KEY=TRAINING_YOSHIZUMI
BACKLOG_REGISTRY_RECORD_TYPE_FIELD_ID=123456
BACKLOG_WORLD_KEY_FIELD_ID=123457
BACKLOG_ACHIEVEMENT_KEY_FIELD_ID=123458
BACKLOG_DONE_STATUS_ID=4
BACKLOG_REGISTRY_ISSUE_TYPE_ID=1
BACKLOG_REGISTRY_PRIORITY_ID=3
TERRARIA_COLLECTION_CHEST_NAME=BACKLOG_COLLECTION
```

ID は実環境を取得して設定する。ドキュメント中の例を固定値として実装しない。

### 9.4 起動前検証

Laravel Command を提供する。

```text
php artisan terraria:doctor
```

`doctor` は read-only とし、次を確認する。

- Backlog API 認証
- `BACKLOG_PROJECT_KEY === TRAINING_YOSHIZUMI` であること。異なる値なら Project の実在有無に関係なく失敗
- `TRAINING_YOSHIZUMI` の Project ID を取得できること
- 3 Custom Field の ID / 型 / Project 所属
- Done Status ID が対象 Project で有効
- Registry Issue Type ID が有効
- Registry Priority ID が有効
- World allowlist
- Collection Chest 名設定
- `TERRARIA_SUPPORTED_RUNTIME` が実装の compatibility matrix に存在すること
- version-pinned Item catalog が対象 Terraria version 用に存在すること

不足していても Project 構造を自動変更しない。`doctor` が失敗する設定で運用開始しない。

---

## 10. Registry Repository

### 10.1 Registry 課題

Registry は Backlog 上の通常の Issue を専用 Record として利用する。

例:

```text
件名:
[Terraria Registry] Rod of Discord

Terraria Record Type:
registry

Terraria World Key:
terraria:123456789

Terraria Key:
item:1326

状態:
完了
```

説明には診断用 metadata を記録してよい。登録日時を正確な過去の入手日時とは扱わない。

### 10.2 論理 Primary Key

Registry の論理 Primary Key は次である。

```text
(Project, Terraria World Key, Terraria Key)
```

MVP の Project は `TRAINING_YOSHIZUMI` 固定である。Backlog は DB の UNIQUE 制約を提供しないため、一意性は PHP の処理で維持する。

### 10.3 Snapshot-scoped Registry Index

Snapshot の Achievement ごとに全ページ検索を繰り返さない。

Snapshot 処理の開始時に、対象 Project / World の Registry 候補を一度だけ全ページ取得し、PHP 内で次の index を構築する。

```text
registryIndex[achievement_key] = RegistryIssue[]
```

API query 自体を `TRAINING_YOSHIZUMI` の Project ID に限定し、PHP 上の final match でも次をすべて完全一致確認する。

1. Issue の Project ID が `doctor` で解決した `TRAINING_YOSHIZUMI` の Project ID と一致
2. `Terraria Record Type === registry`
3. `Terraria World Key === world_key`
4. `Terraria Key === achievement_key`

Backlog の文字列 filter を DB の exact lookup とみなさない。検索失敗は「0件」と扱わない。

### 10.4 `ensureRegistered` と重複

`ensureRegistered` は Snapshot-scoped `registryIndex` を受け取り、通常の判定では追加の全件検索を行わない。

```text
ensureRegistered(worldKey, achievement, registryIndex)
    |
    +-- exact Registry が0件
    |       -> Registry Issue 作成
    |       -> 完了状態へ更新
    |       -> 作成した Issue の再取得または対象 key の限定再検索で確認
    |       -> registryIndex を更新
    |       -> registered / failed
    |
    +-- exact Registry が1件
    |       +-- 完了済み -> already_registered
    |       +-- 未完了   -> 完了へ更新 -> 再取得確認 -> registered / failed
    |
    +-- exact Registry が複数
            -> warning
            +-- 完了済みが1件以上
            |       -> 論理上 already_registered
            |       -> 自動削除・自動マージしない
            +-- 完了済みが0件
                    -> fail closed
                    -> 新規作成しない
                    -> 既存のどれも自動完了しない
                    -> result = failed
                    -> 管理者診断対象
```

複数 exact Registry で完了済みが0件の場合、どの物理 Issue を正本とするか安全に選べないため書き込みを止める。Collection Chest の成功 ACK も出さない。

Backlog API の create/update 応答が timeout 等で不明になった場合、再作成する前に対象 key を限定して再検索する。

### 10.5 競合制御

MVP は PHP Bridge を単一ホストで運用する。PHP-FPM 等で複数 Worker が存在しても AC-11 を守るため、`world_key + achievement_key` 単位の **cross-process file lock を必須** とする。

例:

```text
/var/lock/terraria-backlog/<sha256(world_key + achievement_key)>.lock
```

- `flock(LOCK_EX)` を使用する。
- lock 範囲は「最新 Registry 確認 -> 必要な create/update -> 保存確認」まで全体を含む。
- 同一ホスト上の全 Worker が同じ lock directory を共有する。
- lock file の内容は正本ではなく、消えてもよい。
- 複数ホストへ水平スケールする構成は MVP 非対応とし、shared distributed lock を導入するまでは禁止する。

この lock と search-first を併用して重複を抑える。物理重複を完全に排除できない場合でも §10.4 の fail-closed 処理で誤更新を防ぐ。

---

## 11. Mapping Repository

攻略課題は次をすべて満たす場合にのみ Mapping として扱う。

- Issue の Project ID が `TRAINING_YOSHIZUMI` の解決済み Project ID と一致
- `Terraria Record Type` が null / 空文字である
- 課題が未完了
- `Terraria World Key` が設定済み
- `Terraria Key` が設定済み

`Terraria Record Type === registry` は Registry として扱い Mapping から除外する。

`Terraria Record Type` に `foo` 等の未知の非空値が入っている Issue は **攻略課題と推測せず無視**し、invalid record type として診断ログを残す。

Mapping のない研修課題は無視する。World Key / Terraria Key の片方だけ設定されている場合も invalid mapping としてログに残すが更新しない。

同一 Achievement に複数の攻略課題を対応させてよい。後付け Mapping は最大でも次の periodic reconciliation で検出される。

Mapping も Snapshot 単位で対象 Project / World の未完了 Issue を一度取得し、PHP 内で Achievement Key ごとに index 化する。

```text
mappingIndex[achievement_key] = MappingIssue[]
```

---

## 12. Backlog Sync

Snapshot 処理の基本順序は以下とする。

```text
1. Request validation
2. Snapshot -> Achievement 候補生成
3. 対象 Project / World の Registry を一度取得して registryIndex を構築
4. 対象 Project / World の未完了 Mapping 課題を一度取得して mappingIndex を構築
5. 各 Achievement を registryIndex で評価し、必要な Registry だけ作成/修復
6. 作成/修復した対象 key だけ再取得して保存確認し、registryIndex を更新
7. 完了済み Registry set と mappingIndex をメモリ上で照合
8. 対応する攻略課題を Done Status へ更新
9. 結果を Adapter へ返す
```

Snapshot 内に Item が多数あっても、Registry / Mapping の全件ページングを Achievement ごとに繰り返さない。

### 12.1 冪等性

課題更新前に現在状態を確認する。既に完了の場合は PATCH しない。

完了済みの一般課題は Registry として取り込まず、コメント・本文・Mapping の補完も行わない。

達成済み Mapping 課題を利用者が再オープンした場合は、次回 reconciliation で再び完了させる。

### 12.2 課題へのコメント

MVP では自動コメントを追加しない。

理由:

- 再同期でコメントが増殖することを避ける。
- 完了 Status が同期結果の正本で十分である。
- 既存研修課題への変更範囲を最小化する。

---

## 13. Backlog API Client

### 13.1 認証

API Key は Nulab 公式 Backlog API の `Backlog-API-Key` request header を使用する。

```http
Backlog-API-Key: <api-key>
```

Backlog API は API Key を `apiKey` query parameter または `Backlog-API-Key` header で送信できる。MVP では URL / access log に秘密情報が残るリスクを減らすため header を採用する。

公式仕様:

https://developer.nulab.com/ja/docs/backlog/auth/

OAuth を将来採用する場合は `Authorization: Bearer <access-token>` を使用するが、MVP の API Key と混同しない。

### 13.2 Request 方針

- Issue List query は必ず対象 `TRAINING_YOSHIZUMI` の Project ID へ限定する。
- Backlog API 呼び出しは1ユーザー/API Keyにつき直列に行う。
- Issue List は `count=100` を使用し、必要なら `offset` で全ページ取得する。
- API Filter 後も PHP で Project ID と Custom Field を exact match する。
- Request timeout を設定する。

既定値:

```text
connect timeout: 2 seconds
request timeout: 8 seconds
```

### 13.3 Rate Limit

Backlog の `X-RateLimit-*` Header をログ用 metadata として読み取る。

`429 Too Many Requests` ではゲーム側を長時間待たせない。

- 当該処理を `failed/retriable` とする。
- 成功 ACK を返さない。
- `X-RateLimit-Reset` を診断ログに残す。
- 次の periodic reconciliation で再評価する。

必要に応じて短い transient retry を1回まで実施してよいが、永続 retry queue は作らない。

### 13.4 5xx / Timeout

Registry の存在確認ができなければ未登録と断定しない。

Create / Update の結果が不明なら、次回検索で現在状態を確認する。

---

## 14. Reconciliation

Reconciliation は独自の Achievement ルールを持たず、Snapshot 処理を再実行する仕組みとする。

### 14.1 契機

| 契機 | 実行元 |
| --- | --- |
| Server startup | Adapter |
| 60秒 periodic | Adapter |
| Chest change | Adapter |
| World progress change | Adapter。取得可能な Hook は latency 改善用途 |
| `/backlog sync` | 管理者 |

Boss event Hook は必須の正本ではない。Hook が取れなくても Periodic Snapshot で補完できる状態だけを対象にする。

### 14.2 復旧可能範囲

| 残っている根拠 | 復旧 |
| --- | --- |
| World flag | Boss / World Registry を作成可能 |
| Collection Chest 内の Item | Item Registry を作成可能 |
| Registry | Item が取り出されていても Mapping 課題を同期可能 |
| Mapping + Registry | 後付け課題を完了可能 |
| 何も残っていない | 復元しない |

---

## 15. ACK 設計

Collection Chest の ACK は「Registry が Backlog へ保存済みであること」だけを意味する。

```text
[Backlog] Rod of Discord を登録しました。取り出してOKです。
```

課題 Mapping が存在することや課題完了成功は ACK 条件に含めない。

### 15.1 宛先

`collection_change` の `trigger.playerNames` に記録された Player へ通知する。同一 debounce window の複数 Player は重複排除して保持する。

Periodic reconciliation 等で操作 Player が不明な場合は、登録成功を Server console へ記録する。全体チャットへの繰り返し通知は行わない。

### 15.2 Partial failure

同じ Chest に3 Item があり、2件成功・1件失敗した場合、成功した2 Item のみ ACK してよい。失敗した Item は成功扱いにしない。

物理重複 Registry が複数存在し、完了済みが0件で fail closed した場合も ACK を出さない。

---

## 16. Security

### 16.1 Adapter → PHP

同一ホストで運用する場合:

- PHP は loopback で Listen してよい。
- HTTP を許可する。
- Bearer Token は必須。

別ホスト・コンテナ Network の信頼境界を越える場合:

- HTTPS を必須とする。
- Bearer Token を必須とする。

Snapshot は現在状態を再送する冪等データであるため、MVP では nonce/replay DB を導入しない。

### 16.2 Backlog

Backlog API Key は PHP Bridge のみが保持する。

次へ渡してはならない。

- Terraria Client
- TShock Adapter
- ゲーム内 Chat
- Repository
- Backlog Issue 本文
- Application Log

HTTP Client の request/exception logging では `Backlog-API-Key` を必ず redact する。

### 16.3 Input trust

Adapter Payload 内の以下を外部操作先として信用しない。

- URL
- Backlog Space
- Project Key
- Issue Key
- Custom Field ID
- Status ID

これらは PHP 設定からのみ取得する。

---

## 17. Logging / Observability

PHP は以下を構造化ログとして出力する。

- `request_id`
- `world_key`
- `reason`
- `achievement_key`
- `registry_issue_key`
- `mapping_issue_key`
- `operation`
- `result`
- `http_status`
- `error_type`

秘密情報は出力しない。

Player Name は ACK 相関に必要な場合のみ扱い、Backlog Registry の必須属性にはしない。

代表 operation:

```text
snapshot.received
snapshot.runtime_rejected
registry.index_loaded
registry.lookup
registry.created
registry.exists
registry.duplicate_failed
registry.failed
mapping.index_loaded
mapping.invalid_record_type
issue.completed
issue.completion_failed
reconciliation.completed
```

---

## 18. Error Handling

### 18.1 PHP Bridge が利用不能

Adapter は HTTP Error をログに残すがゲームを停止させない。

Collection Item に成功 ACK は出さない。次回 Snapshot で再試行する。

### 18.2 Backlog が利用不能

PHP は `503` 相当の処理結果を Adapter へ返す。

ゲーム側は成功 ACK を出さない。

Boss / World 状態は次回 Snapshot で再判定する。Item は Collection Chest に残っていれば再判定できる。既に Registry 保存済みなら Item が取り出されても後付け Mapping は可能。

### 18.3 Custom Field / Project / Runtime 設定不備

誤った課題更新を避けるため fail closed とする。

- `BACKLOG_PROJECT_KEY !== TRAINING_YOSHIZUMI`
- Project ID / Custom Field / Status 等の設定不整合
- unsupported Terraria / TShock runtime
- Item catalog 不在

のいずれかでは同期を開始しない。

`terraria:doctor` が失敗する状態では運用開始しない。Runtime mismatch は Adapter 起動時にも拒否し、PHP は各 Snapshot でも再検証する。

---

## 19. Domain / Application Component

PHP 側は少なくとも次の責務へ分割する。

```text
Domain/
  AchievementKey
  WorldKey
  Achievement
  WorldSnapshot
  ItemCatalog

Application/
  ProcessWorldSnapshot
  EvaluateAchievements
  RegisterAchievements
  SynchronizeMappedIssues

Infrastructure/Backlog/
  BacklogClient
  RegistryRepository
  MappingRepository
  ProjectConfigurationRepository
```

### 19.1 Interface

概念 Interface:

```php
interface RegistryRepository
{
    public function loadIndex(WorldKey $world): RegistryIndex;

    public function ensureRegistered(
        WorldKey $world,
        Achievement $achievement,
        RegistryIndex $index,
    ): RegistryResult;
}
```

```php
interface MappingRepository
{
    public function loadIncompleteIndex(WorldKey $world): MappingIndex;

    public function complete(Mapping $mapping): CompletionResult;
}
```

Domain/Application 層は、Registry の実体が Backlog Issue であることへ直接依存しない。

将来 Registry 保存先を変更する場合も Interface の実装を差し替えられる構造とする。ただし MVP では Backlog 以外の永続ストアを実装しない。

---

## 20. Backlog Search / Pagination Algorithm

Registry / Mapping 共通で、Backlog API が返す1ページだけを全件とみなさない。

Snapshot ごとに Repository 種別ごとの scan を原則1回だけ行う。

```text
offset = 0
count = 100

loop:
    issues = GET /api/v2/issues(projectId[]=TRAINING_YOSHIZUMI_ID, ..., offset, count)
    collect issues

    if issues.count < count:
        break

    offset += count
```

取得後に Project ID と Custom Field を PHP で完全一致比較し、`registryIndex` / `mappingIndex` を構築する。

新規 Registry 作成後の保存確認だけは、作成した Issue の GET または対象 Achievement Key を限定した再検索を許可する。Achievement ごとの全 Project scan は行わない。

検索障害は「該当0件」と扱わない。

---

## 21. Backlog Write Sequence

### Registry 新規作成

```text
cross-process lock 取得
  |
  v
lock 内で最新 exact Registry を再確認
  |
  +-- 存在 -> §10.4 に従う
  |
  +-- 不在
        POST /api/v2/issues
          -> Registry Issue作成

        PATCH /api/v2/issues/{issueKey}
          statusId = configured done status

        GET issue または対象 key の限定再検索
          -> Project + custom fields + done を確認

lock 解放
```

途中で失敗して未完了 Registry が残っても、次回 `ensureRegistered` がそれを検出して完了へ進める。ただし同じ exact Registry が複数存在する場合は §10.4 の重複規則を優先する。

### Mapping 課題

```text
GET current issue
  -> Project + 未完了 + exact Mapping + Record Type空欄を再確認

PATCH /api/v2/issues/{issueKey}
  statusId = configured done status
```

更新前に既に完了していた場合は何もしない。

---

## 22. Testing Strategy

### 22.1 PHP Unit Test

対象:

- Raw flag -> Achievement Key 変換
- Item ID が version catalog に存在すること
- `stack` が integer かつ `1..maxStack` であること
- Eater/Brain・Wall of Flesh を推測しないこと
- World Key exact match
- Project ID exact match
- Registry duplicate 判定
- 複数 exact Registry + 完了0件の fail closed
- Mapping validation
- unknown Record Type の拒否
- Reopened Issue の再完了判定

### 22.2 PHP Feature Test

Laravel HTTP Test + Backlog HTTP Fake を利用する。

最低限:

- Snapshot endpoint authentication
- runtime version mismatch rejection
- invalid world rejection
- configurable Collection Chest name validation
- invalid / unknown Item ID rejection
- string / float / zero / maxStack超過の stack rejection
- `BACKLOG_PROJECT_KEY !== TRAINING_YOSHIZUMI` の doctor failure
- Registry query が Project ID に限定されること
- Registry none -> create -> done -> verify
- Registry exists -> no create
- create timeout -> target key re-search
- incomplete Registry recovery
- duplicate incomplete Registry -> no write / failed
- Snapshot 内に複数 Achievement があっても full Registry / Mapping scan が各1回であること
- Backlog 429 / 5xx
- post-hoc Mapping
- same Achievement -> multiple issues
- mapping-less general issue ignored
- unknown non-empty Record Type ignored
- completed general issue ignored

実 Backlog へ接続するテストは CI で実施しない。

### 22.3 Contract Test

`contracts/examples` に Snapshot fixture を置き、

- C# serializer output
- PHP request validation
- runtime version fields
- `collectionChestName`
- integer Item type / stack

の双方が同じ schema に適合することを確認する。

### 22.4 Adapter Test

C# Unit Test:

- runtime compatibility gate
- Snapshot builder
- `world_key` generation / override
- configurable Collection Chest name filter
- Chest state normalization
- debounce
- collection trigger player aggregation
- periodic Snapshot が collection ACK trigger を破棄しないこと
- single-flight sender
- ACK result filtering

TShock Hook そのものは、対応バージョンの実サーバーを使った Smoke Test を別途行う。

### 22.5 Concurrency Test

同一 `world_key + achievement_key` に複数 PHP Worker 相当の並行リクエストを発生させ、shared file lock により lookup-create-verify が直列化されることを確認する。

### 22.6 Manual Acceptance

実 `TRAINING_YOSHIZUMI` を使う試験は CI ではなく手動 Acceptance とする。

試験前に専用 Test Issue / Mapping を用意し、既存研修課題を誤更新しないことを確認する。

---

## 23. Acceptance Criteria Traceability

| AC | Design の主な対応箇所 |
| --- | --- |
| AC-01 | 2, 3, 7, 16 |
| AC-02 | 2, 9, 10 |
| AC-03 | 6, 8.3, 10, 15 |
| AC-04 | 7.3, 8.3 |
| AC-05 | 10, 15 |
| AC-06 | 11, 12, 14 |
| AC-07 | 11, 12 |
| AC-08 | 7.2, 8, 14 |
| AC-09 | 7.4, 13, 18 |
| AC-10 | 12, 13, 14 |
| AC-11 | 10.4, 10.5, 12 |
| AC-12 | 10.4, 13.4 |
| AC-13 | 5, 11 |
| AC-14 | 11, 12 |
| AC-15 | 11, 12 |
| AC-16 | 6.4, 16, 17 |
| AC-17 | 8, 14, 18 |
| AC-18 | 7.6, 13, 18 |
| AC-19 | 9, 10.3, 11, 12 |

---

## 24. 実装前に確認する外部依存

以下はコードを書き始める前に確認し、実値を設定する。

1. 実運用時の Terraria と TShock stable の対応バージョン。
2. 対応 TShock で `ChestItemChange`、Quick Stack Hook、World State field が利用できること。
3. 対象 Terraria version の Item ID / maxStack catalog を生成・固定できること。
4. `TRAINING_YOSHIZUMI` で Text Custom Field を3つ利用できる権限・プランであること。
5. Custom Field ID、Done Status ID、Issue Type ID、Priority ID。
6. Backlog API Key に対象課題の Read / Add / Update 権限があること。
7. 実際の World で `Main.worldID` を取得し、コピー運用時に override が必要か確認すること。

この確認で仕様を満たせない項目が見つかった場合、推測で代替実装せず spec / design の変更としてレビューする。

---

## 25. 実装しないもの

Design の段階でも以下を追加しない。

- SQLite / MySQL / PostgreSQL
- Redis 等の永続 Queue
- ローカル Achievement Event Store
- 一過性イベントを救済する Outbox
- Steam API
- Player Inventory 監視
- Backlog から Terraria への逆同期
- Client MOD
- tModLoader
- 複数 Achievement の AND / OR Mapping
- 自動 Backlog Project / Custom Field 作成
- 未確認の Boss 撃破推測
- 複数 PHP ホストでの水平スケール

---

## 26. 設計上の決定まとめ

| 論点 | 決定 |
| --- | --- |
| 主実装 | PHP 8.5 / Laravel 13 |
| Terraria 側 | TShock Plugin / C# |
| C# の責務 | Snapshot取得・送信・ACKのみ |
| アプリ DB | なし |
| 正本 | Terraria current state + Backlog Registry |
| Mapping | Backlog Custom Field |
| Registry | 同一 Backlog Project 内の専用 Issue |
| 対象 Project | `TRAINING_YOSHIZUMI` 固定。doctor / query / final match で検証 |
| World Key | `Main.worldID` ベース + override |
| Item 判定 | version-pinned Item catalog + Collection Chest current state |
| Collection Chest 名 | 設定可能。既定値 `BACKLOG_COLLECTION` |
| Chest event | dirty trigger として使用。差分を達成とはみなさない |
| ACK trigger | collection Snapshot を periodic で破棄せず Player を集約 |
| Reconciliation | startup + 60秒 periodic + manual |
| 後付け Mapping | periodic cycle で検出 |
| Boss 判定 | 永続 state のみ |
| Wall of Flesh | 独立 Achievement は MVP 対象外。`world:hardmode` のみ |
| Eater / Brain | 共通 flag のため個別 Achievement は MVP 対象外 |
| Backlog API | `Backlog-API-Key` header、Project限定、pagination、exact filter |
| API効率 | Snapshot単位で Registry / Mapping を1回 scan して index 化 |
| Registry重複 | shared file lock + search-first。複数未完了重複は fail closed |
| PHP concurrency | 同一ホスト全 Worker で cross-process `flock`。水平scale非対応 |
| Runtime version | Adapter startup + PHP Snapshot validation の二重 Gate |
| 障害復旧 | 再判定可能な current state / Registry のみ |

この design を `split-tasks` の入力とし、実装タスクは仕様 AC と本書の節を参照できる形で分割する。
