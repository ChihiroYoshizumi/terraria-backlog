# contracts

Adapter (C#) と Bridge (PHP) の間で共有する Snapshot request/response の JSON Schema と example。
`docs/design.md` §4, §6 を正本とする。

```text
snapshot-v1.schema.json           Adapter -> Bridge の Snapshot request (docs/design.md §6.2)
snapshot-response-v1.schema.json  Bridge -> Adapter の response (docs/design.md §6.5, notification-only)
items-v1.schema.json              contracts/terraria/<version>/items.json の形式
examples/snapshot-v1.json         request の例
examples/snapshot-response-v1.json response の例
terraria/1.4.5.6/items.json       採用 Terraria version の Item catalog (id/name/maxStack)
```

`terraria/1.4.5.6/items.json` は Task 01 時点では最小限のプレースホルダー
（`Dirt Block` / `Stone Block` / `Rod of Discord` のみ）であり、Task 04 が実データへ拡張する。

## 検証コマンド

```bash
cd contracts
npm install
npm run validate
```

`scripts/validate.js` が以下を確認する。

- `examples/snapshot-v1.json` が `snapshot-v1.schema.json` に適合する。
- `examples/snapshot-response-v1.json` が `snapshot-response-v1.schema.json` に適合する
  （`additionalProperties: false` により Achievement Key / Backlog Issue Key / Registry 結果 /
  Mapping 結果が含まれていないことを保証する）。
- `terraria/1.4.5.6/items.json` が `items-v1.schema.json` に適合する。
- response example に禁止 field（`achievementKey` 等）が存在しない。
