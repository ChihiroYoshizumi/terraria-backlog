<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Support\RuntimeConfigurationChecker;
use App\Infrastructure\Backlog\BacklogClient;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\ProjectConfigurationRepository;
use Illuminate\Console\Command;

/**
 * 起動前検証 (docs/design.md §9.4, docs/spec.md §10)。
 *
 * - read-only。Backlog 側の Project / Custom Field / Status を自動作成・修正しない。
 * - 各検証項目を OK / NG で表示する。
 * - critical NG が1つでもあれば non-zero exit code を返す (fail closed, §18.4)。
 * - API Key の値は絶対に表示しない (AC-16)。出力は必ず redact を通す。
 */
final class TerrariaDoctorCommand extends Command
{
    protected $signature = 'terraria:doctor';

    protected $description = 'Backlog Project 設定と Terraria runtime 設定を read-only で検証する';

    public function handle(
        ProjectConfigurationRepository $projectConfiguration,
        RuntimeConfigurationChecker $runtimeConfiguration,
        BacklogClient $client,
    ): int {
        $this->line('terraria:doctor — read-only の設定検証 (docs/design.md §9.4)');
        $this->newLine();

        $report = $projectConfiguration->verify()->merge($runtimeConfiguration->check());

        foreach ($report->checks as $check) {
            $this->renderCheck($client, $check);
        }

        $this->newLine();

        $failures = $report->failures();

        if ($failures !== []) {
            $this->error(sprintf(
                '設定検証に失敗した (%d / %d 項目)。この状態で同期を開始しない。',
                count($failures),
                count($report->checks),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('全 %d 項目の検証に成功した。', count($report->checks)));

        return self::SUCCESS;
    }

    private function renderCheck(BacklogClient $client, ConfigurationCheck $check): void
    {
        $label = match ($check->status) {
            ConfigurationCheck::STATUS_OK => '<fg=green>[ OK ]</>',
            ConfigurationCheck::STATUS_SKIPPED => '<fg=yellow>[SKIP]</>',
            default => '<fg=red>[ NG ]</>',
        };

        // 生成側で秘密情報を含めない設計だが、出力直前でも必ず redact する。
        $detail = $client->redact($check->detail);

        // 空白による桁揃えはコンソール描画で潰れるため、区切り文字で並べる。
        $this->line(sprintf(
            '%s %s%s',
            $label,
            $check->name,
            $detail === '' ? '' : ' — '.$detail,
        ));
    }
}
