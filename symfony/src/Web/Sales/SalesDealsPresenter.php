<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;
use App\Web\Sales\ViewModel\SalesDealsViewModel;

final class SalesDealsPresenter
{
    /** @param array<string,mixed> $data */
    public function present(
        array $data,
        DataGridQuery $query,
        ?string $error = null,
    ): SalesDealsViewModel {
        $items = $this->list($data['items'] ?? null);
        $pagination = is_array($data['pagination'] ?? null) ? $data['pagination'] : [];
        $page = max(1, (int) ($pagination['page'] ?? $query->page));
        $perPage = max(10, (int) ($pagination['per_page'] ?? $query->perPage));
        $hasMore = (bool) ($pagination['has_more'] ?? false);

        $rows = [];
        foreach ($items as $deal) {
            $risk = strtoupper(trim((string) ($deal['risk_level'] ?? 'NORMAL')));
            $currency = trim((string) ($deal['currency'] ?? 'USD')) ?: 'USD';

            $rows[] = [
                'id' => (int) ($deal['id'] ?? 0),
                'deal' => trim(
                    (string) ($deal['customer'] ?? '—')
                    . ' · '
                    . (string) ($deal['public_id'] ?? '')
                    . ' · '
                    . (string) ($deal['source'] ?? ''),
                    ' ·'
                ),
                'stage' => (string) ($deal['stage_name'] ?? $deal['stage_code'] ?? '—'),
                'owner' => trim((string) ($deal['owner_name'] ?? '')) ?: 'Unassigned',
                'value' => $this->money($deal['deal_value'] ?? 0, $currency),
                'next_action' => (string) ($deal['next_action_at'] ?? '—'),
                'attention' => (string) ($deal['attention_reason'] ?? '—'),
                'risk' => $risk !== '' ? $risk : 'NORMAL',
            ];
        }

        $knownBefore = ($page - 1) * $perPage;
        $knownTotal = $knownBefore + count($rows) + ($hasMore ? 1 : 0);
        $gridState = $error !== null
            ? DataGridState::Error
            : ($rows === [] ? DataGridState::Empty : DataGridState::Ready);

        return new SalesDealsViewModel(
            query: $query,
            page: new DataGridPage($rows, $knownTotal, $page, $perPage),
            gridState: $gridState,
            columns: [
                new DataGridColumn('deal', 'Deal / Client', mobilePriority: 10),
                new DataGridColumn('stage', 'Stage', mobilePriority: 20),
                new DataGridColumn('owner', 'Owner', mobilePriority: 30),
                new DataGridColumn('value', 'Value', mobilePriority: 40, align: 'end'),
                new DataGridColumn('next_action', 'Next action', mobilePriority: 50),
                new DataGridColumn('attention', 'Attention', mobilePriority: 60),
                new DataGridColumn('risk', 'Risk', mobilePriority: 70),
            ],
            filters: $this->filters($data, $query),
            rowActions: [
                new UIAction(
                    id: 'sales.deal.view',
                    label: 'Open',
                    intent: UIActionIntent::View,
                ),
            ],
            error: $error,
        );
    }

    /** @param array<string,mixed> $data @return list<DataGridFilter> */
    private function filters(array $data, DataGridQuery $query): array
    {
        $pipelines = $this->list($data['pipelines'] ?? null);
        $owners = $this->list($data['owners'] ?? null);
        $selectedPipelineId = $query->filters['pipeline_id'] ?? '';

        $pipelineOptions = ['' => 'All'];
        $stageOptions = ['' => 'All'];
        foreach ($pipelines as $pipeline) {
            $pipelineId = (string) ($pipeline['id'] ?? '');
            if ($pipelineId === '') {
                continue;
            }
            $pipelineOptions[$pipelineId] = (string) ($pipeline['name'] ?? $pipeline['code'] ?? 'Pipeline');

            if ($selectedPipelineId !== '' && $selectedPipelineId !== $pipelineId) {
                continue;
            }
            foreach ($this->list($pipeline['stages'] ?? null) as $stage) {
                $stageId = (string) ($stage['id'] ?? '');
                if ($stageId !== '') {
                    $stageOptions[$stageId] = (string) ($stage['name'] ?? $stage['code'] ?? 'Stage');
                }
            }
        }

        $ownerOptions = ['' => 'All'];
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
            $label = trim((string) ($owner['full_name'] ?? '')) ?: trim((string) ($owner['email'] ?? ''));
            $ownerOptions[(string) $id] = $label !== '' ? $label : ('#' . $id);
        }

        return [
            new DataGridFilter('owner_id', 'Owner', $ownerOptions, $query->filters['owner_id'] ?? null),
            new DataGridFilter(
                'priority',
                'Priority',
                ['' => 'All', 'low' => 'Low', 'normal' => 'Normal', 'high' => 'High', 'urgent' => 'Urgent'],
                $query->filters['priority'] ?? null,
            ),
            new DataGridFilter(
                'source',
                'Source',
                [],
                $query->filters['source'] ?? null,
                type: 'text',
                placeholder: 'Source',
            ),
            new DataGridFilter('pipeline_id', 'Pipeline', $pipelineOptions, $query->filters['pipeline_id'] ?? null),
            new DataGridFilter('stage_id', 'Stage', $stageOptions, $query->filters['stage_id'] ?? null),
            new DataGridFilter(
                'risk',
                'Risk',
                ['' => 'All', 'high' => 'High'],
                $query->filters['risk'] ?? null,
            ),
        ];
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
