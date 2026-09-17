#!/usr/bin/env node
/**
 * contracts/ 配下の schema と example/実データを検証する。
 * Task 01 の自動確認: 「snapshot-v1.schema.json に example request が適合する」
 * 「example response が notification-only contract になっている」を満たす。
 *
 * Usage: node scripts/validate.js  (contracts/ ディレクトリから実行する想定)
 */
"use strict";

const fs = require("fs");
const path = require("path");
const Ajv = require("ajv");
const addFormats = require("ajv-formats");

const root = path.resolve(__dirname, "..");

function loadJson(relPath) {
  const full = path.join(root, relPath);
  return JSON.parse(fs.readFileSync(full, "utf8"));
}

const ajv = new Ajv({ allErrors: true, strict: false });
addFormats(ajv);

const cases = [
  {
    label: "snapshot-v1 request example",
    schema: "snapshot-v1.schema.json",
    data: "examples/snapshot-v1.json",
  },
  {
    label: "snapshot-response-v1 example (notification-only)",
    schema: "snapshot-response-v1.schema.json",
    data: "examples/snapshot-response-v1.json",
  },
  {
    label: "terraria 1.3.0.8 items catalog",
    schema: "items-v1.schema.json",
    data: "terraria/1.3.0.8/items.json",
  },
];

let failed = false;

for (const c of cases) {
  const schema = loadJson(c.schema);
  const data = loadJson(c.data);
  const validate = ajv.compile(schema);
  const valid = validate(data);
  if (valid) {
    console.log(`OK   ${c.label}`);
  } else {
    failed = true;
    console.error(`NG   ${c.label}`);
    console.error(JSON.stringify(validate.errors, null, 2));
  }
}

// notification-only 契約は response schema の additionalProperties: false が担保している
// (docs/design.md §6.5)。example を個別に検査しても schema 検証と重複するだけなので、
// ここでは「その担保自体が外されていないこと」を検証する。
const responseSchema = loadJson("snapshot-response-v1.schema.json");
if (responseSchema.additionalProperties !== false) {
  failed = true;
  console.error(
    "NG   snapshot-response-v1.schema.json の additionalProperties: false が外れています。" +
      " Achievement Key / Backlog Issue Key / Registry・Mapping 結果の漏洩を防げません。"
  );
} else {
  console.log("OK   response schema enforces notification-only (additionalProperties: false)");
}

// items catalog は PHP が type(=id) をキーに maxStack を引く前提 (docs/design.md:212, §6.4)。
// uniqueItems はオブジェクト全体の一致しか見ないため、id の重複はここで検出する。
const itemsCatalog = loadJson("terraria/1.3.0.8/items.json");
const seenItemIds = new Set();
const duplicateItemIds = new Set();
for (const item of itemsCatalog.items ?? []) {
  if (seenItemIds.has(item.id)) {
    duplicateItemIds.add(item.id);
  }
  seenItemIds.add(item.id);
}
if (duplicateItemIds.size > 0) {
  failed = true;
  console.error(
    `NG   items catalog に重複した id があります: ${[...duplicateItemIds].join(", ")}`
  );
} else {
  console.log("OK   items catalog has unique ids");
}

process.exit(failed ? 1 : 0);
