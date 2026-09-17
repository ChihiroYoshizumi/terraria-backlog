<?php

declare(strict_types=1);

namespace App\Domain\ItemCatalog;

use JsonException;

/**
 * `contracts/terraria/<version>/items.json` を読み込む catalog reader
 * (docs/design.md §6.4, contracts/items-v1.schema.json)。
 *
 * - runtime Terraria version と一致する catalog だけを返す。
 * - 読めない場合は例外にする (fail closed)。別 version へのフォールバックはしない。
 * - 同一 version は 1 度だけ読み、プロセス内で使い回す (永続キャッシュは持たない)。
 */
final class JsonFileItemCatalogRepository implements ItemCatalogRepository
{
    /**
     * ディレクトリ名に使える version 文字列。パス要素の混入を防ぐ。
     */
    private const string VERSION_PATTERN = '/\A[0-9A-Za-z][0-9A-Za-z._-]*\z/';

    /** @var array<string, ItemCatalog> */
    private array $loaded = [];

    public function __construct(private readonly string $basePath) {}

    public function forVersion(string $terrariaVersion): ItemCatalog
    {
        if (isset($this->loaded[$terrariaVersion])) {
            return $this->loaded[$terrariaVersion];
        }

        if (preg_match(self::VERSION_PATTERN, $terrariaVersion) !== 1 || str_contains($terrariaVersion, '..')) {
            throw ItemCatalogUnavailable::invalidVersionString($terrariaVersion);
        }

        $path = $this->pathFor($terrariaVersion);

        if (! is_file($path) || ! is_readable($path)) {
            throw ItemCatalogUnavailable::fileMissing($terrariaVersion, $path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw ItemCatalogUnavailable::fileMissing($terrariaVersion, $path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ItemCatalogUnavailable::unreadable($terrariaVersion, $path, 'invalid JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw ItemCatalogUnavailable::unreadable($terrariaVersion, $path, 'root must be a JSON object');
        }

        $declaredVersion = $decoded['terrariaVersion'] ?? null;
        if (! is_string($declaredVersion) || $declaredVersion === '') {
            throw ItemCatalogUnavailable::unreadable($terrariaVersion, $path, 'missing "terrariaVersion"');
        }

        if ($declaredVersion !== $terrariaVersion) {
            throw ItemCatalogUnavailable::versionMismatch($terrariaVersion, $declaredVersion, $path);
        }

        $rawItems = $decoded['items'] ?? null;
        if (! is_array($rawItems)) {
            throw ItemCatalogUnavailable::unreadable($terrariaVersion, $path, 'missing "items" array');
        }

        $entries = [];
        foreach ($rawItems as $index => $rawItem) {
            $entries[] = self::toEntry($terrariaVersion, $path, $index, $rawItem);
        }

        return $this->loaded[$terrariaVersion] = ItemCatalog::fromEntries($terrariaVersion, $entries);
    }

    public function pathFor(string $terrariaVersion): string
    {
        return rtrim($this->basePath, '/').'/'.$terrariaVersion.'/items.json';
    }

    private static function toEntry(string $version, string $path, mixed $index, mixed $rawItem): ItemCatalogEntry
    {
        $at = 'items['.(is_scalar($index) ? (string) $index : '?').']';

        if (! is_array($rawItem)) {
            throw ItemCatalogUnavailable::unreadable($version, $path, $at.' must be an object');
        }

        $id = $rawItem['id'] ?? null;
        $name = $rawItem['name'] ?? null;
        $maxStack = $rawItem['maxStack'] ?? null;

        if (! is_int($id)) {
            throw ItemCatalogUnavailable::unreadable($version, $path, $at.'.id must be an integer');
        }

        if (! is_string($name) || $name === '') {
            throw ItemCatalogUnavailable::unreadable($version, $path, $at.'.name must be a non-empty string');
        }

        if (! is_int($maxStack) || $maxStack < 1) {
            throw ItemCatalogUnavailable::unreadable($version, $path, $at.'.maxStack must be an integer >= 1');
        }

        return new ItemCatalogEntry($id, $name, $maxStack);
    }
}
