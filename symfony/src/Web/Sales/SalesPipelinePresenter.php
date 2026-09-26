<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesPipelineViewModel;

final class SalesPipelinePresenter
{
    /** @param array<string,mixed> $data @param array<string,mixed> $filters */
    public function present(
        array $data,
        array $filters,
        ?string $error = null,
    ): SalesPipelineViewModel {
        $pipelines = $this->list($data['pipelines'] ?? null);
        $deals = $this->list($data['deals'] ?? null);
        $owners = $this->list($data['owners'] ?? null);

        $selectedPipelineId = trim((string) ($filters['pipeline_id'] ?? ''));
        $pipeline = null;
        foreach ($pipelines as $candidate) {
            if ($selectedPipelineId !== ''
                && (string) ($candidate['id'] ?? '') === $selectedPipelineId
            ) {
                $pipeline = $candidate;
                break;
            }
        }
        $pipeline ??= $pipelines[0] ?? ['id' => '', 'name' => 'Sales Pipeline', 'stages' => []];

        $pipelineId = (string) ($pipeline['id'] ?? '');
        $pipelineOptions = [];
        foreach ($pipelines as $candidate) {
            $id = (string) ($candidate['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $pipelineOptions[] = [
                'id' => $id,
                'label' => (string) ($candidate['name'] ?? $candidate['code'] ?? 'Pipeline'),
            ];
        }

        $ownerOptions = [];
        foreach ($owners as $owner) {
            if (
                strtolower((string) ($owner['status'] ?? '')) !== 'active'
                || !in_array(
                    strtolower((string) ($owner['organization_role'] ?? $owner['role'] ?? '')),
                    ['manager', 'admin'],
                    true,
                )
            ) {
                continue;
            }

            $id = (int) ($owner['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $label = trim((string) ($owner['full_name'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($owner['email'] ?? ''));
            }

            $ownerOptions[] = [
                'id' => $id,
                'label' => $label !== '' ? $label : ('#' . $id),
            ];
        }

        $byStage = [];
        foreach ($deals as $deal) {
            if ((string) ($deal['pipeline_id'] ?? '') !== $pipelineId) {
                continue;
            }

            $stageId = (string) ($deal['stage_id'] ?? '');
            $byStage[$stageId][] = $deal;
        }

        $stageOptions = [];
        $stages = [];
        $visibleDeals = 0;

        foreach ($this->list($pipeline['stages'] ?? null) as $stage) {
            $stageId = (string) ($stage['id'] ?? '');
            if ($stageId === '') {
                continue;
            }

            $stageLabel = (string) ($stage['name'] ?? $stage['code'] ?? 'Stage');
            $stageOptions[] = ['id' => $stageId, 'label' => $stageLabel];

            $items = [];
            foreach ($byStage[$stageId] ?? [] as $deal) {
                $currency = trim((string) ($deal['currency'] ?? 'USD')) ?: 'USD';
                $risk = strtoupper(trim((string) ($deal['risk_level'] ?? 'NORMAL')));
                $items[] = [
                    'id' => (int) ($deal['id'] ?? 0),
                    'stageId' => (string) ($deal['stage_id'] ?? ''),
                    'customer' => (string) ($deal['customer'] ?? '—'),
                    'title' => (string) ($deal['title'] ?? $deal['public_id'] ?? 'Deal'),
                    'owner' => trim((string) ($deal['owner_name'] ?? '')) ?: 'Unassigned',
                    'daysInStage' => (int) ($deal['days_in_stage'] ?? 0),
                    'stageAgeEstimated' => (bool) ($deal['stage_age_estimated'] ?? false),
                    'value' => $this->money($deal['deal_value'] ?? 0, $currency),
                    'weightedValue' => $this->money($deal['weighted_value'] ?? 0, $currency),
                    'nextAction' => (string) ($deal['next_action_at'] ?? 'Not set'),
                    'attentionReason' => (string) ($deal['attention_reason'] ?? '—'),
                    'recommendation' => (string) ($deal['ai_recommendation'] ?? 'Continue planned next action'),
                    'riskLabel' => $risk !== '' ? $risk : 'NORMAL',
                    'riskTone' => $risk === 'HIGH' ? 'danger' : 'neutral',
                    'href' => '/sales/deals/' . (int) ($deal['id'] ?? 0),
                ];
            }

            $visibleDeals += count($items);
            $stages[] = [
                'id' => $stageId,
                'label' => $stageLabel,
                'count' => count($items),
                'summary' => sprintf(
                    '%d deals · %s · weighted %s · avg %dd',
                    (int) ($stage['deal_count'] ?? count($items)),
                    $this->money($stage['pipeline_value'] ?? 0, 'USD'),
                    $this->money($stage['weighted_value'] ?? 0, 'USD'),
                    (int) ($stage['avg_days_in_stage'] ?? 0),
                ),
                'deals' => $items,
            ];
        }

        return new SalesPipelineViewModel(
            pipelineId: $pipelineId,
            pipelineName: (string) ($pipeline['name'] ?? $pipeline['code'] ?? 'Sales Pipeline'),
            pipelineOptions: $pipelineOptions,
            ownerOptions: $ownerOptions,
            filters: [
                'pipeline_id' => $pipelineId,
                'owner_id' => trim((string) ($filters['owner_id'] ?? '')),
                'risk' => trim((string) ($filters['risk'] ?? '')),
                'priority' => trim((string) ($filters['priority'] ?? '')),
                'value_min' => trim((string) ($filters['value_min'] ?? '')),
                'value_max' => trim((string) ($filters['value_max'] ?? '')),
                'source' => trim((string) ($filters['source'] ?? '')),
                'q' => trim((string) ($filters['q'] ?? '')),
            ],
            stageOptions: $stageOptions,
            stages: $stages,
            visibleDeals: $visibleDeals,
            error: $error,
        );
    }

    private function money(mixed $value, string $currency): string
    {
        return number_format((float) $value, 0, '.', ' ') . ' ' . $currency;
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
