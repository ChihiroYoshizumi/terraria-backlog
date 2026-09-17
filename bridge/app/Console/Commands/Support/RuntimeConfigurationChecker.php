<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationReport;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Backlog API を使わないローカル設定の検証 (docs/design.md §2.3, §5.2, §9.4)。
 *
 * ここで読む設定は Task 02 / Task 04 が所有する。設定が存在しない場合は
 * `terraria:doctor` が NG を出して落ちるだけで済むよう、欠損に耐える形で読む。
 *
 * 期待する設定キー:
 * - `terraria.allowed_world_keys`   (TERRARIA_ALLOWED_WORLD_KEYS)
 * - `terraria.collection_chest_name` (TERRARIA_COLLECTION_CHEST_NAME)
 * - `terraria.supported_runtime`    (TERRARIA_SUPPORTED_RUNTIME)
 * - `item_catalog.base_path`        (TERRARIA_ITEM_CATALOG_PATH。未設定なら contracts/terraria を見る)
 */
final class RuntimeConfigurationChecker
{
    /**
     * 実装が認識する Terraria / TShock の組み合わせ (docs/design.md §2.3)。
     *
     * §2.3 がリポジトリ全体の正本であり、ここはその PHP 側の写しである。
     * 形式は `TERRARIA_SUPPORTED_RUNTIME=<tshock>:<terraria>`。
     *
     * @var array<string, string> runtime 設定値 => Terraria version
     */
    public const SUPPORTED_RUNTIME_MATRIX = [
        '4.3.13:1.3.0.8' => '1.3.0.8',
    ];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly string $contractsCatalogPath,
    ) {}

    public function check(): ConfigurationReport
    {
        $checks = [
            $this->checkWorldAllowlist(),
            $this->checkCollectionChestName(),
        ];

        [$runtimeCheck, $terrariaVersion] = $this->checkSupportedRuntime();
        $checks[] = $runtimeCheck;
        $checks[] = $this->checkItemCatalog($terrariaVersion);

        return new ConfigurationReport($checks);
    }

    /**
     * docs/design.md §5.2: payload の world.key を信用せず、許可リストで限定する。
     */
    private function checkWorldAllowlist(): ConfigurationCheck
    {
        $name = 'terraria.world_allowlist';
        $configured = $this->config->get('terraria.allowed_world_keys');

        if (is_string($configured)) {
            $configured = array_values(array_filter(array_map('trim', explode(',', $configured)), static fn (string $v): bool => $v !== ''));
        }

        if (! is_array($configured) || $configured === []) {
            return ConfigurationCheck::failed(
                $name,
                'TERRARIA_ALLOWED_WORLD_KEYS (config terraria.allowed_world_keys) が未設定。許可ワールドを明示すること。',
            );
        }

        foreach ($configured as $worldKey) {
            if (! is_string($worldKey) || trim($worldKey) === '') {
                return ConfigurationCheck::failed($name, 'World allowlist に空文字または文字列以外が含まれている。');
            }
        }

        return ConfigurationCheck::ok($name, sprintf('許可ワールド %d 件。', count($configured)));
    }

    /**
     * docs/spec.md §6: Collection Chest 名は設定可能とする。
     */
    private function checkCollectionChestName(): ConfigurationCheck
    {
        $name = 'terraria.collection_chest_name';
        $configured = $this->config->get('terraria.collection_chest_name');

        if (! is_string($configured) || trim($configured) === '') {
            return ConfigurationCheck::failed(
                $name,
                'TERRARIA_COLLECTION_CHEST_NAME (config terraria.collection_chest_name) が未設定。',
            );
        }

        return ConfigurationCheck::ok($name, sprintf('"%s"。', $configured));
    }

    /**
     * docs/design.md §2.3: 未対応の Terraria / TShock 組み合わせでは運用を開始しない。
     *
     * @return array{0: ConfigurationCheck, 1: string|null}
     */
    private function checkSupportedRuntime(): array
    {
        $name = 'terraria.supported_runtime';
        $configured = $this->config->get('terraria.supported_runtime');

        if (! is_string($configured) || trim($configured) === '') {
            return [
                ConfigurationCheck::failed(
                    $name,
                    'TERRARIA_SUPPORTED_RUNTIME (config terraria.supported_runtime) が未設定。',
                ),
                null,
            ];
        }

        $configured = trim($configured);

        if (! array_key_exists($configured, self::SUPPORTED_RUNTIME_MATRIX)) {
            return [
                ConfigurationCheck::failed(
                    $name,
                    sprintf(
                        'TERRARIA_SUPPORTED_RUNTIME="%s" は実装の compatibility matrix に存在しない (既知: %s)。',
                        $configured,
                        implode(', ', array_keys(self::SUPPORTED_RUNTIME_MATRIX)),
                    ),
                ),
                null,
            ];
        }

        return [
            ConfigurationCheck::ok($name, sprintf('"%s" は compatibility matrix に存在する。', $configured)),
            self::SUPPORTED_RUNTIME_MATRIX[$configured],
        ];
    }

    /**
     * docs/design.md §4, §6.4: version-pinned Item catalog を入力検証に使う。
     */
    private function checkItemCatalog(?string $terrariaVersion): ConfigurationCheck
    {
        $name = 'terraria.item_catalog';

        if ($terrariaVersion === null) {
            return ConfigurationCheck::skipped($name, 'supported runtime を解決できないため対象 Terraria version を特定できない。');
        }

        $path = $this->resolveCatalogPath($terrariaVersion);

        if (! is_file($path) || ! is_readable($path)) {
            return ConfigurationCheck::failed(
                $name,
                sprintf('Terraria %s 用の Item catalog が見つからない: %s', $terrariaVersion, $path),
            );
        }

        $contents = file_get_contents($path);
        $decoded = $contents === false ? null : json_decode($contents, true);

        if (! is_array($decoded)) {
            return ConfigurationCheck::failed($name, sprintf('Item catalog を JSON として読めない: %s', $path));
        }

        if (($decoded['terrariaVersion'] ?? null) !== $terrariaVersion) {
            return ConfigurationCheck::failed(
                $name,
                sprintf('Item catalog の terrariaVersion が %s と一致しない: %s', $terrariaVersion, $path),
            );
        }

        $items = $decoded['items'] ?? null;

        if (! is_array($items) || $items === []) {
            return ConfigurationCheck::failed($name, sprintf('Item catalog に items が存在しない: %s', $path));
        }

        return ConfigurationCheck::ok($name, sprintf('Terraria %s: %d 件。', $terrariaVersion, count($items)));
    }

    /**
     * 評価側 (`JsonFileItemCatalogRepository::pathFor()`) と同じ base path・
     * 同じ組み立て規則で解決する。ここがズレると doctor が「運用で実際に読む
     * catalog」とは別のファイルを検証したまま OK を返してしまう。
     */
    private function resolveCatalogPath(string $terrariaVersion): string
    {
        $configured = $this->config->get('item_catalog.base_path');

        $basePath = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : $this->contractsCatalogPath;

        return rtrim($basePath, '/').'/'.$terrariaVersion.'/items.json';
    }
}
