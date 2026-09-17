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
    label: "terraria 1.4.5.6 items catalog",
    schema: "items-v1.schema.json",
    data: "terraria/1.4.5.6/items.json",
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

// レスポンス contract に Achievement Key / Backlog Issue Key 等が漏れていないことを明示的に確認する。
const forbiddenResponseKeys = [
  "achievementKey",
  "backlogIssueKey",
  "registryResult",
  "mappingResult",
];
const responseExample = loadJson("examples/snapshot-response-v1.json");
for (const key of forbiddenResponseKeys) {
  if (Object.prototype.hasOwnProperty.call(responseExample, key)) {
    failed = true;
    console.error(`NG   response example に禁止 field が含まれています: ${key}`);
  }
}
if (!failed) {
  console.log("OK   response example is notification-only (no Achievement/Backlog leakage)");
}

process.exit(failed ? 1 : 0);
