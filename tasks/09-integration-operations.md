# Task 09: 統合テスト・運用手順・Smoke Test

## 目的
AC-01〜AC-19 を実装結果へ trace し、実 Backlog/TShock 環境で安全に導入・確認できる状態にする。

## スコープ
- PHP Feature/Contract/Concurrency Test の不足分補完
- C# Adapter Test の不足分補完
- `contracts/examples` の request/response fixture 整備
- AC-01〜AC-19 の traceability 表を README または運用 doc へ追加
- `terraria:doctor` を含む起動/設定手順
- 必須環境変数一覧
- Custom Field / Status / Issue Type / Priority ID 確認手順
- 対応 Terraria/TShock version 確認と Smoke Test 手順
- 実 `TRAINING_YOSHIZUMI` での手動 Acceptance 手順
- 障害時の確認 / 手動 `/backlog sync` 手順
- API Key を出さないログ確認

## 依存関係
- Task 07
- Task 08

## 仕様・設計参照
- `docs/spec.md` §11 AC-01〜AC-19
- `docs/specs/*`
- `docs/design.md` §22〜§26

## 対象 AC
- AC-01〜AC-19

## 必須 Acceptance シナリオ
- Backlog 停止 -> ACK なし -> 復旧 -> 再照合
- post-hoc Mapping periodic/manual
- duplicate Registry / timeout / reopen / invalid mapping
- World A/B 分離
- 完了済み研修課題・mapping-less 課題を変更しない
- Vanilla client + TShock 実機 Smoke Test

## 完了条件
- 各 AC について「どの自動テスト or Manual Acceptance で確認するか」が一意に追跡できる
- 実装時に追加された仕様外保証がないことを最終確認する
- MVP 導入者が README/運用 doc だけでセットアップと復旧確認を実行できる
