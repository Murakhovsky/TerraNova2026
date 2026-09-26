<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesAdminDashboardViewModel;

final class SalesAdminDashboardPresenter
{
    /** @param array<string,mixed> $data */
    public function present(array $data, ?string $error = null): SalesAdminDashboardViewModel
    {
        $pipelines = $this->list($data['pipelines'] ?? null);
        $metrics = is_array($data['metrics'] ?? null) ? $data['metrics'] : [];

        $cards = [];
        foreach ($pipelines as $pipeline) {
            $stages = $this->list($pipeline['stages'] ?? null);
            $cards[] = [
                'id' => (string) ($pipeline['id'] ?? ''),
                'name' => (string) ($pipeline['name'] ?? $pipeline['code'] ?? 'Pipeline'),
                'code' => (string) ($pipeline['code'] ?? ''),
                'isDefault' => (bool) ($pipeline['is_default'] ?? false),
                'stageCount' => count($stages),
                'stages' => array_map(
                    static fn (array $stage): array => [
                        'name' => (string) ($stage['name'] ?? $stage['code'] ?? 'Stage'),
                        'code' => (string) ($stage['code'] ?? ''),
                        'probability' => (int) ($stage['probability_default'] ?? 0),
                    ],
                    $stages,
                ),
            ];
        }

        return new SalesAdminDashboardViewModel(
            pipelines: $cards,
            kpis: [
                ['label' => 'Pipelines', 'value' => (string) count($pipelines), 'hint' => 'Configured runtime'],
                ['label' => 'Won rate / 30d', 'value' => (string) ($metrics['won_rate'] ?? 0) . '%', 'hint' => 'Outcome signal'],
                ['label' => 'Follow-up completion', 'value' => (string) ($metrics['followup_completion_rate'] ?? 0) . '%', 'hint' => 'Operational discipline'],
                ['label' => 'Admin layer', 'value' => 'Canonical', 'hint' => 'Wave 13'],
            ],
            areas: [
                ['label' => 'Pipelines', 'href' => '/sales/admin/pipelines', 'copy' => 'Воронки, stages, transitions, loss reasons та revisions.'],
                ['label' => 'Business Rules', 'href' => '/sales/admin/rules', 'copy' => 'WHEN → IF → THEN automation configuration.'],
                ['label' => 'Sales Agents', 'href' => '/sales/admin/agents', 'copy' => 'Sales Intelligence business configuration and safe tests.'],
                ['label' => 'Actions & Policies', 'href' => '/sales/admin/actions', 'copy' => 'Automation policy and execution constraints.'],
                ['label' => 'Users & Teams', 'href' => '/sales/admin/teams', 'copy' => 'Membership, assignment and explicit capabilities.'],
                ['label' => 'Integrations', 'href' => '/sales/admin/integrations', 'copy' => 'CRM/provider configuration and routing.'],
                ['label' => 'Health & Audit', 'href' => '/sales/admin/health', 'copy' => 'Operational health, audit and configuration evidence.'],
            ],
            error: $error,
        );
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }
}
