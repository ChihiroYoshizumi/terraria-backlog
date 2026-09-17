<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ItemCatalog;

use App\Domain\ItemCatalog\ItemCatalogUnavailable;
use App\Domain\ItemCatalog\JsonFileItemCatalogRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * docs/design.md §6.4 の version-pinned Item catalog reader。
 */
final class JsonFileItemCatalogRepositoryTest extends TestCase
{
    private const string SUPPORTED_VERSION = '1.3.0.8';

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/item-catalog-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    private static function contractsPath(): string
    {
        return dirname(__DIR__, 5).'/contracts/terraria';
    }

    /**
     * リポジトリ同梱の実カタログ (contracts/terraria/1.3.0.8/items.json) を読む。
     * catalog データ側の壊れ・version 取り違えもここで検出する。
     */
    public function test_loads_the_bundled_terraria_catalog(): void
    {
        $catalog = (new JsonFileItemCatalogRepository(self::contractsPath()))
            ->forVersion(self::SUPPORTED_VERSION);

        $this->assertSame(self::SUPPORTED_VERSION, $catalog->terrariaVersion);
        $this->assertGreaterThan(3000, $catalog->count(), 'catalog should cover the 1.3.0.8 item id space');

        // Terraria 1.3.0.8 の実値 (TShock 4.3.13 同梱 TerrariaServer.exe から抽出)。
        // 1.4 系で変わった値 (ブロックの 9999 等) を入れてしまうと落ちる。
        $this->assertSame('Dirt Block', $catalog->get(2)?->name);
        $this->assertSame(999, $catalog->get(2)?->maxStack);
        $this->assertSame('Stone Block', $catalog->get(3)?->name);
        $this->assertSame(999, $catalog->get(3)?->maxStack);
        $this->assertSame('Rod of Discord', $catalog->get(1326)?->name);
        $this->assertSame(1, $catalog->get(1326)?->maxStack);
        $this->assertSame('Copper Coin', $catalog->get(71)?->name);
        $this->assertSame(100, $catalog->get(71)?->maxStack);
        $this->assertSame('Celestial Sigil', $catalog->get(3601)?->name);
        $this->assertSame(20, $catalog->get(3601)?->maxStack);

        // Item ID 0 は「アイテム無し」であり catalog には含めない。
        $this->assertFalse($catalog->has(0));
        $this->assertFalse($catalog->has(3602));
    }

    public function test_missing_catalog_is_a_fail_closed_configuration_error(): void
    {
        $repository = new JsonFileItemCatalogRepository($this->tempDir);

        $this->expectException(ItemCatalogUnavailable::class);

        $repository->forVersion('9.9.9.9');
    }

    /**
     * runtime version と異なる catalog を暗黙に流用しない。
     */
    public function test_catalog_declaring_another_version_is_rejected(): void
    {
        $this->writeCatalog('1.4.4.9', '{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"Dirt Block","maxStack":999}]}');

        $repository = new JsonFileItemCatalogRepository($this->tempDir);

        $this->expectException(ItemCatalogUnavailable::class);
        $this->expectExceptionMessageMatches('/version-pinned/');

        $repository->forVersion('1.4.4.9');
    }

    public function test_other_versions_are_not_used_as_a_fallback(): void
    {
        $this->writeCatalog('1.3.0.8', '{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"Dirt Block","maxStack":999}]}');

        $repository = new JsonFileItemCatalogRepository($this->tempDir);

        $this->assertTrue($repository->forVersion('1.3.0.8')->has(2));

        $this->expectException(ItemCatalogUnavailable::class);
        $repository->forVersion('1.4.4.9');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function brokenCatalogs(): array
    {
        return [
            ['not json at all'],
            ['[]'],
            ['{"items":[]}'],
            ['{"terrariaVersion":"1.3.0.8"}'],
            ['{"terrariaVersion":"1.3.0.8","items":{"2":{"id":2}}}'],
            ['{"terrariaVersion":"1.3.0.8","items":[{"id":"2","name":"Dirt Block","maxStack":999}]}'],
            ['{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"","maxStack":999}]}'],
            ['{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"Dirt Block","maxStack":0}]}'],
            ['{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"Dirt Block","maxStack":"999"}]}'],
            ['{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"A","maxStack":1},{"id":2,"name":"B","maxStack":1}]}'],
        ];
    }

    #[DataProvider('brokenCatalogs')]
    public function test_broken_catalog_fails_closed(string $contents): void
    {
        $this->writeCatalog('1.3.0.8', $contents);

        $this->expectException(ItemCatalogUnavailable::class);

        (new JsonFileItemCatalogRepository($this->tempDir))->forVersion('1.3.0.8');
    }

    /**
     * 空の catalog は「全 Item が catalog に無い = 全 skip」を正常系にしてしまうため、
     * catalog そのものが壊れているとみなす (fail closed)。
     *
     * @return list<array{0: string}>
     */
    public static function emptyOrNonListItems(): array
    {
        return [
            'empty list' => ['{"terrariaVersion":"1.3.0.8","items":[]}'],
            'empty object' => ['{"terrariaVersion":"1.3.0.8","items":{}}'],
            'keyed map' => ['{"terrariaVersion":"1.3.0.8","items":{"2":{"id":2,"name":"Dirt Block","maxStack":999}}}'],
        ];
    }

    #[DataProvider('emptyOrNonListItems')]
    public function test_catalog_without_a_non_empty_item_list_fails_closed(string $contents): void
    {
        $this->writeCatalog('1.3.0.8', $contents);

        $this->expectException(ItemCatalogUnavailable::class);

        (new JsonFileItemCatalogRepository($this->tempDir))->forVersion('1.3.0.8');
    }

    /**
     * AchievementKey::item() は正の Item ID を前提にしているため、
     * 0 / 負値は catalog ロード時点で止める (Snapshot 処理中に漏らさない)。
     *
     * @return list<array{0: string}>
     */
    public static function nonPositiveItemIds(): array
    {
        return [
            'zero' => ['{"terrariaVersion":"1.3.0.8","items":[{"id":0,"name":"None","maxStack":1}]}'],
            'negative' => ['{"terrariaVersion":"1.3.0.8","items":[{"id":-1,"name":"Legacy Alias","maxStack":1}]}'],
            'negative among valid' => ['{"terrariaVersion":"1.3.0.8","items":[{"id":2,"name":"Dirt Block","maxStack":999},{"id":-48,"name":"Legacy Alias","maxStack":1}]}'],
        ];
    }

    #[DataProvider('nonPositiveItemIds')]
    public function test_non_positive_item_id_fails_closed(string $contents): void
    {
        $this->writeCatalog('1.3.0.8', $contents);

        $this->expectException(ItemCatalogUnavailable::class);
        $this->expectExceptionMessageMatches('/\.id must be an integer >= 1/');

        (new JsonFileItemCatalogRepository($this->tempDir))->forVersion('1.3.0.8');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function unsafeVersionStrings(): array
    {
        return [['../1.3.0.8'], ['1.3.0.8/../../etc'], ['/absolute'], [''], ['..']];
    }

    #[DataProvider('unsafeVersionStrings')]
    public function test_version_string_cannot_escape_the_catalog_directory(string $version): void
    {
        $this->expectException(ItemCatalogUnavailable::class);

        (new JsonFileItemCatalogRepository($this->tempDir))->forVersion($version);
    }

    private function writeCatalog(string $version, string $contents): void
    {
        $dir = $this->tempDir.'/'.$version;
        mkdir($dir, 0o777, true);
        file_put_contents($dir.'/items.json', $contents);
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            is_dir($child) ? $this->removeTree($child) : unlink($child);
        }

        rmdir($path);
    }
}
