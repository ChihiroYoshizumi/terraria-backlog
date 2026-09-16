# Task 01: プロジェクト土台と共有 Contract

## 目的
`docs/design.md` §2〜§4 を実装へ落とし込み、後続 Task が独立して進められる最小の土台を作る。

## スコープ
- `bridge/` に Laravel 13 / PHP 8.5 の API アプリ土台
- `adapter/` に TShock Plugin 用 .NET/C# プロジェクト土台
- `contracts/` に Snapshot v1 と notification-only response の schema/example
- `contracts/terraria/<supported-version>/items.json` を配置できる構造
- Bridge は DB / Migration / Eloquent / DB Queue / DB Session を前提にしない
- 最小の formatter / test runner / CI 実行口

## 非スコープ
- Backlog API 接続
- Snapshot endpoint のドメイン処理
- Achievement 評価
- TShock hook 実装

## 依存関係
なし。最初に実装する。

## 仕様・設計参照
- `docs/spec.md` §4
- `docs/design.md` §1〜§4, §19

## 対象 AC
- AC-01
- AC-02

## 実装制約
- `QUEUE_CONNECTION=sync`, `SESSION_DRIVER=array`, `CACHE_STORE=array` 相当で成立させる
- DB migration を実装開始条件にしない
- Adapter 側に Achievement Key / Backlog Issue Key 等のドメイン型を持ち込まない

## テスト
- Bridge の test command が DB 無しで成功する
- Adapter の unit test project が起動する
- JSON schema/example が機械検証可能

## 完了条件
- 後続 Task がこの構造を前提にブランチを切れる
- AC-01/02 を阻害する DB 依存・Client MOD 依存が入っていない
