# Implementation Tasks

このディレクトリを `spec-driven-dev` の `implement-task` が参照する実装タスクの正本とする。

## 正本の優先順位

1. `docs/spec.md`
2. `docs/specs/*`
3. `docs/design.md`
4. `tasks/*.md`

上位ドキュメントと Task が矛盾する場合は上位を優先する。実装上の判断で spec/design から逸脱する必要が出た場合は、コード側で独自解釈せず spec/design issue として止める。

## 実装順

1. `01-project-foundation.md`
2. `02-snapshot-api-validation.md`
3. `03-backlog-client-doctor.md`
4. `04-achievement-evaluation.md`
5. `05-registry-repository.md`
6. `06-mapping-sync.md`
7. `07-reconciliation-notifications.md`
8. `08-tshock-adapter.md`
9. `09-integration-operations.md`

## 依存関係

```text
01
 ├─> 02
 ├─> 03
 └─> 04

03 + 04 -> 05
03 + 05 -> 06
02 + 04 + 05 + 06 -> 07
01 + 02 -> 08
07 + 08 -> 09
```

`02` / `03` / `04` は `01` 完了後、共有 Contract を壊さない範囲で並行実装可能。

## 実装ルール

- 1 Task = 1 PR を基本とする。
- PR本文に Task file、参照 spec/design 節、対象 AC、追加/更新テストを記載する。
- DB・永続 Queue・Outbox を追加しない。
- C# Adapter に Achievement / Backlog のドメインロジックを入れない。
- 実装 PR のレビューでは must-fix の仕様逸脱と optional な実装改善を分ける。
- 修正後は同じ AC / 設計節に対して再レビューする。

## 完了条件

Task 01〜09 が完了し、AC-01〜AC-19 について「実装箇所 / 自動テストまたは Manual Acceptance / PR」が追跡できること。
