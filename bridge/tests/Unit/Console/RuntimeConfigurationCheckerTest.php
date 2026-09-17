<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\Support\RuntimeConfigurationChecker;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationReport;
use Illuminate\Config\Repository as ConfigRepository;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/design.md §2.3 / §5.2 / §9.4 のローカル設定検証。
 *
 * ここで読む config は Task 02 / Task 04 の所有物であり、本 Task では
 * config/terraria.php を作らない。欠損に耐えることを含めて検証する。
 */
final class RuntimeConfigurationCheckerTest extends TestCase
{
    private string $catalogRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogRoot = sys_get_temp_dir().'/terraria-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->catalogRoot.'/1.3.0.8', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->catalogRoot.'/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->catalogRoot.'/*') ?: [] as $dir) {
            rmdir($dir);
        }

        if (is_dir($this->catalogRoot)) {
            rmdir($this->catalogRoot);
        }

        parent::tearDown();
    }

    private function writeCatalog(string $contents): void
    {
        file_put_contents($this->catalogRoot.'/1.3.0.8/items.json', $contents);
    }

    private function validCatalog(): string
    {
        return (string) json_encode([
            'terrariaVersion' => '1.3.0.8',
            'items' => [['id' => 1326, 'name' => 'Rod of Discord', 'maxStack' => 1]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $terraria
     */
    private function check(array $terraria): ConfigurationReport
    {
        $config = new ConfigRepository(['terraria' => $terraria]);

        return (new RuntimeConfigurationChecker($config, $this->catalogRoot))->check();
    }

    private function checkNamed(ConfigurationReport $report, string $name): ConfigurationCheck
    {
        foreach ($report->checks as $check) {
            if ($check->name === $name) {
                return $check;
            }
        }

        $this->fail(sprintf('検証項目 "%s" が report に存在しない。', $name));
    }

    /**
     * @return array<string, mixed>
     */
    private function validConfig(): array
    {
        return [
            'allowed_world_keys' => ['terraria:123456789'],
            'collection_chest_name' => 'BACKLOG_COLLECTION',
            'supported_runtime' => '4.3.13:1.3.0.8',
        ];
    }

    #[Test]
    public function it_passes_with_a_complete_configuration(): void
    {
        $this->writeCatalog($this->validCatalog());

        $this->assertTrue($this->check($this->validConfig())->isSatisfied());
    }

    #[Test]
    public function it_survives_a_completely_absent_terraria_config(): void
    {
        $report = (new RuntimeConfigurationChecker(new ConfigRepository, $this->catalogRoot))->check();

        $this->assertFalse($report->isSatisfied());
        $this->assertCount(4, $report->checks);
    }

    #[Test]
    public function it_accepts_a_comma_separated_world_allowlist(): void
    {
        $this->writeCatalog($this->validCatalog());

        $report = $this->check([...$this->validConfig(), 'allowed_world_keys' => 'terraria:1, terraria:2']);

        $this->assertTrue($this->checkNamed($report, 'terraria.world_allowlist')->isSatisfied());
    }

    #[Test]
    public function it_rejects_an_allowlist_entry_that_is_blank(): void
    {
        $this->writeCatalog($this->validCatalog());

        $report = $this->check([...$this->validConfig(), 'allowed_world_keys' => ['  ']]);

        $this->assertFalse($this->checkNamed($report, 'terraria.world_allowlist')->isSatisfied());
    }

    #[Test]
    public function it_rejects_a_runtime_outside_the_compatibility_matrix(): void
    {
        $this->writeCatalog($this->validCatalog());

        $report = $this->check([...$this->validConfig(), 'supported_runtime' => '6.1.0:1.4.5.6']);

        $this->assertFalse($this->checkNamed($report, 'terraria.supported_runtime')->isSatisfied());
        // version を特定できないので catalog は「確認できなかった」扱い。
        $this->assertSame(
            ConfigurationCheck::STATUS_SKIPPED,
            $this->checkNamed($report, 'terraria.item_catalog')->status,
        );
    }

    #[Test]
    public function it_rejects_a_catalog_pinned_to_another_terraria_version(): void
    {
        $this->writeCatalog((string) json_encode([
            'terrariaVersion' => '1.4.5.6',
            'items' => [['id' => 1, 'name' => 'x', 'maxStack' => 1]],
        ]));

        $report = $this->check($this->validConfig());

        $this->assertFalse($this->checkNamed($report, 'terraria.item_catalog')->isSatisfied());
    }

    #[Test]
    public function it_rejects_an_unreadable_or_empty_catalog(): void
    {
        $this->writeCatalog('not json');
        $this->assertFalse($this->checkNamed($this->check($this->validConfig()), 'terraria.item_catalog')->isSatisfied());

        $this->writeCatalog((string) json_encode(['terrariaVersion' => '1.3.0.8', 'items' => []]));
        $this->assertFalse($this->checkNamed($this->check($this->validConfig()), 'terraria.item_catalog')->isSatisfied());
    }

    #[Test]
    public function it_reports_a_missing_catalog_file(): void
    {
        $report = $this->check($this->validConfig());

        $this->assertFalse($this->checkNamed($report, 'terraria.item_catalog')->isSatisfied());
    }

    #[Test]
    public function it_resolves_the_catalog_from_the_item_catalog_config(): void
    {
        // 評価側 (AchievementServiceProvider) が読むのと同じ config を doctor も見る。
        $this->writeCatalog($this->validCatalog());

        $config = new ConfigRepository([
            'terraria' => $this->validConfig(),
            'item_catalog' => ['base_path' => $this->catalogRoot],
        ]);

        // 注入された fallback は存在しないパスにしておき、config 側が使われることを示す。
        $report = (new RuntimeConfigurationChecker($config, $this->catalogRoot.'-absent'))->check();

        $this->assertTrue($this->checkNamed($report, 'terraria.item_catalog')->isSatisfied());
    }

    #[Test]
    public function it_fails_when_the_configured_catalog_is_missing_even_if_the_fallback_has_one(): void
    {
        // 回帰: doctor が config を無視して fallback を検証すると、運用で実際に
        // 読まれる catalog が壊れていても OK を返してしまう。
        $this->writeCatalog($this->validCatalog());

        $config = new ConfigRepository([
            'terraria' => $this->validConfig(),
            'item_catalog' => ['base_path' => $this->catalogRoot.'-absent'],
        ]);

        $report = (new RuntimeConfigurationChecker($config, $this->catalogRoot))->check();

        $this->assertFalse($this->checkNamed($report, 'terraria.item_catalog')->isSatisfied());
    }

    #[Test]
    public function the_repository_contract_catalog_matches_the_supported_runtime(): void
    {
        // docs/design.md §2.3 の正本と contracts/terraria/<version>/items.json の整合。
        $version = RuntimeConfigurationChecker::SUPPORTED_RUNTIME_MATRIX['4.3.13:1.3.0.8'];
        $path = dirname(base_path()).'/contracts/terraria/'.$version.'/items.json';

        $this->assertFileExists($path);
    }
}
