<?php

declare(strict_types=1);

namespace Tests\Feature\Snapshot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * tasks/02 テスト「unsupported runtime を reject」。
 *
 * docs/design.md §2.3: PHP は TERRARIA_SUPPORTED_RUNTIME と一致しない Snapshot を
 * 409 で拒否する。対応版は Terraria 1.3.0.8 / TShock 4.3.13。
 */
final class SnapshotRuntimeValidationTest extends SnapshotTestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unsupportedRuntimes(): array
    {
        return [
            'newer tshock' => [['runtime.tshockVersion' => '6.1.0']],
            'newer terraria' => [['runtime.terrariaVersion' => '1.4.5.6']],
            'both newer' => [[
                'runtime.tshockVersion' => '6.1.0',
                'runtime.terrariaVersion' => '1.4.5.6',
            ]],
            'swapped pair' => [[
                'runtime.tshockVersion' => '1.3.0.8',
                'runtime.terrariaVersion' => '4.3.13',
            ]],
            'version with prefix' => [['runtime.terrariaVersion' => 'v1.3.0.8']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[Test]
    #[DataProvider('unsupportedRuntimes')]
    public function it_rejects_an_unsupported_runtime_pair_with_409(array $overrides): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with($overrides))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'runtime.unsupported');

        $this->assertSame([], $processor->received);
    }

    #[Test]
    public function it_accepts_the_supported_runtime_pair_from_the_design_ssot(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::valid())->assertOk();

        $runtime = $processor->last()->runtime;
        $this->assertSame('4.3.13', $runtime->tshockVersion);
        $this->assertSame('1.3.0.8', $runtime->terrariaVersion);
    }

    #[Test]
    public function the_default_supported_runtime_matches_design_section_2_3(): void
    {
        // config/terraria.php の既定値が design §2.3 の正本とずれていないこと。
        // (テスト環境では TERRARIA_SUPPORTED_RUNTIME を設定しないので既定値が出る)
        /** @var array<string, mixed> $defaults */
        $defaults = require base_path('config/terraria.php');

        $this->assertSame('4.3.13:1.3.0.8', $defaults['supported_runtime']);
        $this->assertSame('BACKLOG_COLLECTION', $defaults['collection_chest_name']);
    }

    #[Test]
    public function it_rejects_a_runtime_container_that_is_not_an_object(): void
    {
        $processor = $this->recordProcessor();

        $this->postSnapshot(SnapshotPayload::with(['runtime' => '4.3.13:1.3.0.8']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'runtime.invalid');

        $this->postSnapshot(SnapshotPayload::with(['runtime.terrariaVersion' => null]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'runtime.invalid');

        $this->assertSame([], $processor->received);
    }
}
