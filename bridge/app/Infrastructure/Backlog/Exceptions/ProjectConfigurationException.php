<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog\Exceptions;

use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationReport;
use RuntimeException;

/**
 * Backlog Project 設定が検証を通らなかった (docs/design.md §18.4)。
 *
 * この状態では Registry 作成・課題完了を含む一切の同期を開始しない。
 * `terraria:doctor` が失敗する設定で運用開始しない。
 */
final class ProjectConfigurationException extends RuntimeException
{
    public function __construct(private readonly ConfigurationReport $report)
    {
        $failures = array_map(
            static fn (ConfigurationCheck $check): string => $check->name.': '.$check->detail,
            $report->failures(),
        );

        parent::__construct(
            "Backlog Project 設定が検証を通過していない。`php artisan terraria:doctor` を確認すること。\n"
            .implode("\n", $failures)
        );
    }

    public function report(): ConfigurationReport
    {
        return $this->report;
    }
}
