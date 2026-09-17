<?php

declare(strict_types=1);

namespace App\Infrastructure\Backlog;

use App\Infrastructure\Backlog\Diagnostics\ConfigurationCheck;
use App\Infrastructure\Backlog\Diagnostics\ConfigurationReport;
use App\Infrastructure\Backlog\Exceptions\BacklogApiException;
use App\Infrastructure\Backlog\Exceptions\ProjectConfigurationException;

/**
 * Backlog Project 設定の解決と検証 (docs/design.md §9.1〜§9.4, §19)。
 *
 * read-only に徹する。Project / Custom Field / Status を自動作成・修正しない
 * (docs/design.md §9.2, §9.4)。
 *
 * fail closed (docs/design.md §18.4):
 * - `BACKLOG_PROJECT_KEY !== TRAINING_YOSHIZUMI` なら Project の実在有無に
 *   関係なく失敗させ、Backlog API も呼ばない (誤 Project への接触を避ける)。
 * - API 障害で検証できなかった項目は OK ではなく `skipped` とする。
 *   「確認できなかった」を「問題なし」と読み替えない。
 */
final class ProjectConfigurationRepository
{
    private ?ProjectConfiguration $resolved = null;

    private ?ProjectConfiguration $configuration = null;

    /**
     * @param  array<string, array{id: mixed, name: string}>  $customFields
     */
    public function __construct(
        private readonly BacklogClient $client,
        private readonly string $configuredProjectKey,
        private readonly string $requiredProjectKey,
        private readonly array $customFields,
        private readonly int $textCustomFieldTypeId,
        private readonly mixed $doneStatusId,
        private readonly mixed $registryIssueTypeId,
        private readonly mixed $registryPriorityId,
    ) {}

    /**
     * 検証済み設定を返す。未検証・検証失敗なら例外を投げる。
     *
     * 後続 Repository (Task 05 / 06) はこのメソッド経由でのみ Project ID や
     * Field ID を取得する。
     */
    public function resolve(): ProjectConfiguration
    {
        if ($this->resolved instanceof ProjectConfiguration) {
            return $this->resolved;
        }

        $report = $this->verify();

        if (! $report->isSatisfied()) {
            // 失敗は memoize しない。設定修正後の再実行で再評価できるようにする。
            throw new ProjectConfigurationException($report);
        }

        $configuration = $this->configuration;

        if (! $configuration instanceof ProjectConfiguration) {
            throw new ProjectConfigurationException($report);
        }

        return $this->resolved = $configuration;
    }

    /**
     * すべての検証項目を評価する。検証失敗では例外を投げず report に集約する。
     */
    public function verify(): ConfigurationReport
    {
        $this->configuration = null;

        $checks = [];

        $projectKeyCheck = $this->checkProjectKey();
        $checks[] = $projectKeyCheck;

        $apiKeyCheck = $this->checkApiKey();
        $checks[] = $apiKeyCheck;

        $fieldNames = [
            'record_type' => 'backlog.custom_field.record_type',
            'world_key' => 'backlog.custom_field.world_key',
            'achievement_key' => 'backlog.custom_field.achievement_key',
        ];

        $remaining = [
            'backlog.authentication',
            'backlog.project',
            ...array_values($fieldNames),
            'backlog.done_status',
            'backlog.registry_issue_type',
            'backlog.registry_priority',
        ];

        if (! $projectKeyCheck->isSatisfied() || ! $apiKeyCheck->isSatisfied()) {
            // 誤 Project へ触れないよう API を一切呼ばない。
            foreach ($remaining as $name) {
                $checks[] = ConfigurationCheck::skipped($name, 'Project Key / API Key の前提が満たされていないため検証していない。');
            }

            return new ConfigurationReport($checks);
        }

        try {
            $this->client->get('/api/v2/users/myself');
            $checks[] = ConfigurationCheck::ok('backlog.authentication', 'API Key で認証できた。');
        } catch (BacklogApiException $exception) {
            $checks[] = ConfigurationCheck::failed('backlog.authentication', $this->describe($exception));

            foreach (array_slice($remaining, 1) as $name) {
                $checks[] = ConfigurationCheck::skipped($name, 'Backlog API 認証を確認できないため検証していない。');
            }

            return new ConfigurationReport($checks);
        }

        $projectId = null;

        try {
            $project = $this->client->get('/api/v2/projects/'.rawurlencode($this->requiredProjectKey))->object();
            $projectId = $this->positiveInt($project['id'] ?? null);
            $returnedKey = is_string($project['projectKey'] ?? null) ? $project['projectKey'] : null;

            if ($projectId === null) {
                $checks[] = ConfigurationCheck::failed('backlog.project', 'Project ID を取得できなかった。');
                $projectId = null;
            } elseif ($returnedKey !== $this->requiredProjectKey) {
                // Backlog が別 Project を返した場合に書き込み対象を確定させない。
                $checks[] = ConfigurationCheck::failed(
                    'backlog.project',
                    sprintf('Project Key が一致しない (期待 %s)。', $this->requiredProjectKey),
                );
                $projectId = null;
            } else {
                $checks[] = ConfigurationCheck::ok(
                    'backlog.project',
                    sprintf('%s の Project ID は %d。', $this->requiredProjectKey, $projectId),
                );
            }
        } catch (BacklogApiException $exception) {
            $checks[] = ConfigurationCheck::failed('backlog.project', $this->describe($exception));
        }

        if ($projectId === null) {
            foreach (array_slice($remaining, 2) as $name) {
                $checks[] = ConfigurationCheck::skipped($name, 'Project ID を解決できないため検証していない。');
            }

            return new ConfigurationReport($checks);
        }

        $fieldIds = [];
        $customFieldsError = null;
        $availableFields = [];

        try {
            $availableFields = $this->client->get('/api/v2/projects/'.$projectId.'/customFields')->list();
        } catch (BacklogApiException $exception) {
            // 取得できないことを「Field なし」とも「問題なし」とも読み替えない。
            $customFieldsError = $this->describe($exception);
        }

        foreach ($this->customFields as $key => $definition) {
            $checkName = $fieldNames[$key] ?? 'backlog.custom_field.'.$key;

            if ($customFieldsError !== null) {
                $checks[] = ConfigurationCheck::failed($checkName, $customFieldsError);
                $fieldIds[$key] = null;

                continue;
            }

            [$check, $fieldId] = $this->checkCustomField($projectId, $checkName, $key, $definition, $availableFields);
            $checks[] = $check;
            $fieldIds[$key] = $fieldId;
        }

        [$statusCheck, $doneStatusId] = $this->checkIdInList(
            'backlog.done_status',
            'BACKLOG_DONE_STATUS_ID',
            $this->doneStatusId,
            '/api/v2/projects/'.$projectId.'/statuses',
            '対象 Project の状態',
        );
        $checks[] = $statusCheck;

        [$issueTypeCheck, $issueTypeId] = $this->checkIdInList(
            'backlog.registry_issue_type',
            'BACKLOG_REGISTRY_ISSUE_TYPE_ID',
            $this->registryIssueTypeId,
            '/api/v2/projects/'.$projectId.'/issueTypes',
            '対象 Project の種別',
        );
        $checks[] = $issueTypeCheck;

        [$priorityCheck, $priorityId] = $this->checkIdInList(
            'backlog.registry_priority',
            'BACKLOG_REGISTRY_PRIORITY_ID',
            $this->registryPriorityId,
            '/api/v2/priorities',
            'Space の優先度',
        );
        $checks[] = $priorityCheck;

        $report = new ConfigurationReport($checks);

        if ($report->isSatisfied()
            && isset($fieldIds['record_type'], $fieldIds['world_key'], $fieldIds['achievement_key'])
            && $doneStatusId !== null
            && $issueTypeId !== null
            && $priorityId !== null
        ) {
            $this->configuration = new ProjectConfiguration(
                projectId: $projectId,
                projectKey: $this->requiredProjectKey,
                recordTypeFieldId: $fieldIds['record_type'],
                worldKeyFieldId: $fieldIds['world_key'],
                achievementKeyFieldId: $fieldIds['achievement_key'],
                doneStatusId: $doneStatusId,
                registryIssueTypeId: $issueTypeId,
                registryPriorityId: $priorityId,
            );
        }

        return $report;
    }

    private function checkProjectKey(): ConfigurationCheck
    {
        if ($this->configuredProjectKey === $this->requiredProjectKey) {
            return ConfigurationCheck::ok('backlog.project_key', sprintf('BACKLOG_PROJECT_KEY=%s。', $this->requiredProjectKey));
        }

        return ConfigurationCheck::failed(
            'backlog.project_key',
            sprintf(
                'BACKLOG_PROJECT_KEY は %s でなければならない (docs/design.md §9.1 の safety assertion)。現在値: %s',
                $this->requiredProjectKey,
                $this->configuredProjectKey === '' ? '(未設定)' : $this->configuredProjectKey,
            ),
        );
    }

    private function checkApiKey(): ConfigurationCheck
    {
        if ($this->client->hasApiKey()) {
            // 値そのものは出力しない (docs/design.md §16.2)。
            return ConfigurationCheck::ok('backlog.api_key', 'BACKLOG_API_KEY が設定されている (値は表示しない)。');
        }

        return ConfigurationCheck::failed('backlog.api_key', 'BACKLOG_API_KEY が未設定。');
    }

    /**
     * @param  array{id: mixed, name: string}  $definition
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: ConfigurationCheck, 1: int|null}
     */
    private function checkCustomField(int $projectId, string $checkName, string $key, array $definition, array $fields): array
    {
        $expectedName = $definition['name'];
        $configuredId = $this->positiveInt($definition['id'] ?? null);

        if ($configuredId === null) {
            return [
                ConfigurationCheck::failed(
                    $checkName,
                    sprintf('Custom Field "%s" の ID が未設定または不正 (%s)。', $expectedName, $this->envNameForField($key)),
                ),
                null,
            ];
        }

        foreach ($fields as $field) {
            if ($this->positiveInt($field['id'] ?? null) !== $configuredId) {
                continue;
            }

            $typeId = $field['typeId'] ?? null;
            $fieldProjectId = $this->positiveInt($field['projectId'] ?? null);
            $name = is_string($field['name'] ?? null) ? $field['name'] : '';

            if ($fieldProjectId !== $projectId) {
                return [
                    ConfigurationCheck::failed(
                        $checkName,
                        sprintf('Custom Field ID %d が対象 Project に所属していない。', $configuredId),
                    ),
                    null,
                ];
            }

            if ($typeId !== $this->textCustomFieldTypeId) {
                return [
                    ConfigurationCheck::failed(
                        $checkName,
                        sprintf(
                            'Custom Field ID %d は Text 型 (typeId=%d) ではない (typeId=%s)。',
                            $configuredId,
                            $this->textCustomFieldTypeId,
                            is_scalar($typeId) ? (string) $typeId : 'unknown',
                        ),
                    ),
                    null,
                ];
            }

            if ($name !== $expectedName) {
                // ID の取り違えを検出する。別 Field を Mapping と誤認すると
                // 無関係な課題を完了にしてしまうため critical NG とする。
                return [
                    ConfigurationCheck::failed(
                        $checkName,
                        sprintf('Custom Field ID %d の表示名が "%s" ではない ("%s")。', $configuredId, $expectedName, $name),
                    ),
                    null,
                ];
            }

            return [
                ConfigurationCheck::ok($checkName, sprintf('"%s" (ID %d, Text 型)。', $expectedName, $configuredId)),
                $configuredId,
            ];
        }

        return [
            ConfigurationCheck::failed(
                $checkName,
                sprintf('Custom Field ID %d が対象 Project に存在しない。', $configuredId),
            ),
            null,
        ];
    }

    /**
     * 設定された ID が、指定 endpoint が返す一覧に含まれるかを検証する。
     *
     * @return array{0: ConfigurationCheck, 1: int|null}
     */
    private function checkIdInList(string $checkName, string $envName, mixed $configured, string $path, string $label): array
    {
        $configuredId = $this->positiveInt($configured);

        if ($configuredId === null) {
            return [ConfigurationCheck::failed($checkName, sprintf('%s が未設定または不正。', $envName)), null];
        }

        try {
            $items = $this->client->get($path)->list();
        } catch (BacklogApiException $exception) {
            return [ConfigurationCheck::failed($checkName, $this->describe($exception)), null];
        }

        foreach ($items as $item) {
            if ($this->positiveInt($item['id'] ?? null) === $configuredId) {
                $name = is_string($item['name'] ?? null) ? $item['name'] : '';

                return [
                    ConfigurationCheck::ok($checkName, sprintf('%s に ID %d ("%s") が存在する。', $label, $configuredId, $name)),
                    $configuredId,
                ];
            }
        }

        return [
            ConfigurationCheck::failed($checkName, sprintf('%s に ID %d が存在しない。', $label, $configuredId)),
            null,
        ];
    }

    private function describe(BacklogApiException $exception): string
    {
        // client 側で redact 済みだが、出力経路が増えても漏れないよう再度通す。
        return $this->client->redact($exception->getMessage());
    }

    private function envNameForField(string $key): string
    {
        return match ($key) {
            'record_type' => 'BACKLOG_REGISTRY_RECORD_TYPE_FIELD_ID',
            'world_key' => 'BACKLOG_WORLD_KEY_FIELD_ID',
            'achievement_key' => 'BACKLOG_ACHIEVEMENT_KEY_FIELD_ID',
            default => strtoupper('BACKLOG_'.$key.'_FIELD_ID'),
        };
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9]\d*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}
