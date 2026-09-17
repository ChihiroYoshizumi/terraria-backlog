<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backlog 連携設定 (docs/design.md §9.1〜§9.4, §13)
|--------------------------------------------------------------------------
|
| Backlog を Achievement Registry / Mapping の永続ストレージとして利用するため
| の設定を集約する。ID 類は実環境から取得した値を .env で与える。
| ドキュメント中の例を固定値として実装しない (docs/design.md §9.3)。
|
*/

return [

    /*
    | Backlog Space の Base URL。Backlog 通信は HTTPS とする (docs/spec.md §10)。
    */
    'base_url' => env('BACKLOG_BASE_URL', 'https://fusic.backlog.jp'),

    /*
    | API Key。`Backlog-API-Key` header で送信し、URL / access log に残さない
    | (docs/design.md §13.1)。値はログ・例外・command 出力へ出さない (§16.2)。
    */
    'api_key' => env('BACKLOG_API_KEY'),

    /*
    | 運用対象の Project Key。
    */
    'project_key' => env('BACKLOG_PROJECT_KEY'),

    /*
    | MVP の safety assertion (docs/design.md §9.1)。
    | `project_key` がこの値以外なら Project の実在有無に関係なく fail closed とする。
    | 可変な一般設定ではないため .env から上書きしない。
    */
    'required_project_key' => 'TRAINING_YOSHIZUMI',

    /*
    | 必要な Text 型 Custom Field (docs/design.md §9.2)。
    | `id` は Backlog 上で作成済みの Custom Field ID。`name` は照合する表示名。
    | PHP アプリケーションは Custom Field を自動作成しない。
    */
    'custom_fields' => [
        'record_type' => [
            'id' => env('BACKLOG_REGISTRY_RECORD_TYPE_FIELD_ID'),
            'name' => 'Terraria Record Type',
        ],
        'world_key' => [
            'id' => env('BACKLOG_WORLD_KEY_FIELD_ID'),
            'name' => 'Terraria World Key',
        ],
        'achievement_key' => [
            'id' => env('BACKLOG_ACHIEVEMENT_KEY_FIELD_ID'),
            'name' => 'Terraria Key',
        ],
    ],

    /*
    | Backlog Custom Field の Text 型 typeId。
    | https://developer.nulab.com/docs/backlog/api/2/add-custom-field/
    | 1: Text / 2: Sentence / 3: Number / 4: Date / 5: Single list /
    | 6: Multiple list / 7: Checkbox / 8: Radio
    */
    'custom_field_text_type_id' => 1,

    /*
    | 完了状態 ID。表示名や固定の数値を前提にしない (docs/spec.md §8)。
    */
    'done_status_id' => env('BACKLOG_DONE_STATUS_ID'),

    /*
    | Registry 課題の作成に使う Issue Type / Priority (docs/design.md §9.3)。
    */
    'registry_issue_type_id' => env('BACKLOG_REGISTRY_ISSUE_TYPE_ID'),
    'registry_priority_id' => env('BACKLOG_REGISTRY_PRIORITY_ID'),

    /*
    | Timeout 既定値 (docs/design.md §13.2)。
    | Backlog 通信でゲームのメイン処理を待たせない (docs/spec.md §9)。
    */
    'timeout' => [
        'connect' => (int) env('BACKLOG_CONNECT_TIMEOUT', 2),
        'request' => (int) env('BACKLOG_REQUEST_TIMEOUT', 8),
    ],

    /*
    | Issue List の pagination (docs/design.md §13.2, §20)。
    | Backlog API の count は 1..100。全ページ取得し、1ページを全件とみなさない。
    | `max_pages` は暴走防止の上限で、超過時は「取得完了」とせず例外にする。
    */
    'pagination' => [
        'count' => 100,
        'max_pages' => (int) env('BACKLOG_MAX_PAGES', 200),
    ],

];
