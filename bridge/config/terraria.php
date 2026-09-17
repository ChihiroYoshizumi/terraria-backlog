<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Terraria Snapshot Bridge 設定 (Task 02)
|--------------------------------------------------------------------------
|
| docs/design.md §2.3 / §5.2 / §6.1 / §6.4 / §16 と docs/spec.md §10 の
| 「PHP 設定からのみ取得する」値をここへ集約する。
| Adapter payload の値でこれらを上書きしてはならない (docs/design.md §16.3)。
|
*/

return [

    /*
     * Task 08 実機確認用の診断ログ。true にすると Snapshot の受信内容と
     * Task 04 の評価結果 (boss / world / item) を構造化ログへ出す。
     * Registry 保存や通知生成は行わない。Task 07 実装時に削除する。
     */
    'debug_log_achievements' => (bool) env('TERRARIA_DEBUG_LOG_ACHIEVEMENTS', false),

    /*
    |--------------------------------------------------------------------------
    | Adapter Bearer Token (docs/design.md §6.1, §16.1)
    |--------------------------------------------------------------------------
    |
    | Adapter → PHP の認証に使う共有秘密。Backlog API Key とは別の秘密情報とする。
    | 未設定のまま Snapshot endpoint を公開しない (fail closed)。
    |
    */

    'adapter_token' => env('TERRARIA_ADAPTER_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | 許可する World Key (docs/design.md §5.2, docs/specs/world-identity.md)
    |--------------------------------------------------------------------------
    |
    | カンマ区切り。URL の worldKey・payload の world.key・この allowlist の
    | 3 つが一致しない Snapshot は 403 で拒否する。
    |
    */

    'allowed_world_keys' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TERRARIA_ALLOWED_WORLD_KEYS', ''))),
        static fn (string $key): bool => $key !== '',
    )),

    /*
    |--------------------------------------------------------------------------
    | 対応 runtime pair (docs/design.md §2.3)
    |--------------------------------------------------------------------------
    |
    | 形式は `<tshockVersion>:<terrariaVersion>`。
    | 既定値は design §2.3 が正本とする TShock 4.3.13 / Terraria 1.3.0.8。
    | Snapshot の runtime がこの組み合わせと一致しない場合は 409 で拒否する。
    |
    */

    'supported_runtime' => (string) env('TERRARIA_SUPPORTED_RUNTIME', '4.3.13:1.3.0.8'),

    /*
    |--------------------------------------------------------------------------
    | Collection Chest 名 (docs/spec.md §6, docs/design.md §6.4)
    |--------------------------------------------------------------------------
    |
    | payload の collectionChestName / collectionChests[].name は
    | この設定値と完全一致していなければならない。
    |
    */

    'collection_chest_name' => (string) env('TERRARIA_COLLECTION_CHEST_NAME', 'BACKLOG_COLLECTION'),

    /*
    |--------------------------------------------------------------------------
    | 件数・サイズ上限 (docs/design.md §6.4, docs/spec.md §10)
    |--------------------------------------------------------------------------
    |
    | 「入力の型・サイズ」を検証し、メモリ待機量を制限するための上限。
    | 上限超過は Snapshot 全体を拒否する (request-level validation)。
    |
    */

    'limits' => [
        // Request Body のバイト数上限。超過は 413。
        'request_body_bytes' => (int) env('TERRARIA_MAX_REQUEST_BODY_BYTES', 1_048_576),

        // json_decode の深さ上限。超過は malformed 扱い (400)。
        'json_depth' => (int) env('TERRARIA_MAX_JSON_DEPTH', 32),

        // collectionChests の要素数上限。
        'chests' => (int) env('TERRARIA_MAX_CHESTS', 64),

        // 1 Chest あたりの items 要素数上限 (Terraria 1.3 の Chest は 40 スロット)。
        'items_per_chest' => (int) env('TERRARIA_MAX_ITEMS_PER_CHEST', 40),

        // Snapshot 全体の items 要素数上限。
        'items_total' => (int) env('TERRARIA_MAX_ITEMS_TOTAL', 2_560),

        // flags のキー数上限。
        'flags' => (int) env('TERRARIA_MAX_FLAGS', 256),

        // trigger.playerNames の要素数上限。
        'trigger_player_names' => (int) env('TERRARIA_MAX_TRIGGER_PLAYER_NAMES', 32),

        // 文字列項目 (world.name / chest name / player name / item name 等) の長さ上限。
        'string_length' => (int) env('TERRARIA_MAX_STRING_LENGTH', 512),
    ],

    /*
    |--------------------------------------------------------------------------
    | 禁止 payload キー (docs/design.md §16.3, docs/spec.md §10)
    |--------------------------------------------------------------------------
    |
    | Adapter payload から外部操作先 (URL / Backlog Space / Project Key /
    | Issue Key / Custom Field ID / Status ID / 資格情報) を指定させない。
    | payload のどの階層にこれらのキーが現れても Snapshot 全体を拒否する。
    |
    | 比較は「小文字化し英数字以外を除去した名前」で行うため、
    | ここには正規化済みの名前を書く (例: project_key / projectKey -> projectkey)。
    |
    */

    'forbidden_payload_keys' => [
        // 任意 URL / 送信先
        'url',
        'uri',
        'href',
        'endpoint',
        'baseurl',
        'apiurl',
        'apibase',
        'callback',
        'callbackurl',
        'webhook',
        'webhookurl',

        // Backlog Space
        'space',
        'spacekey',
        'spaceid',
        'spaceurl',
        'backlogspace',

        // Project
        'projectkey',
        'projectid',

        // Issue
        'issuekey',
        'issueid',
        'issueidorkey',
        'parentissueid',

        // Custom Field / Status
        'customfield',
        'customfieldid',
        'customfields',
        'statusid',
        'statuskey',
        'resolutionid',

        // 資格情報
        'apikey',
        'backlogapikey',
        'apitoken',
        'accesstoken',
        'bearertoken',
        'authorization',
    ],

];
