<?php

declare(strict_types=1);

namespace App\Web\Sales;

use App\Web\Sales\ViewModel\SalesDashboardViewModel;

final class SalesDashboardPresenter
{
    /** @param array<string,mixed> $sales */
    public function present(array $sales, ?string $error = null): SalesDashboardViewModel
    {
        $kpis = $this->array($sales['kpis'] ?? null);
        $today = $this->array($sales['today'] ?? null);

        $atRisk = [];
        foreach (array_slice($this->list($sales['at_risk'] ?? null), 0, 8) as $deal) {
            $id = (int) ($deal['id'] ?? 0);
            $atRisk[] = [
                'title' => (string) ($deal['customer'] ?? $deal['title'] ?? 'Deal'),
                'subtitle' => (string) ($deal['stage_name'] ?? ''),
                'meta' => $this->money($deal['deal_value'] ?? $deal['value'] ?? 0),
                'status' => 'HIGH',
                'tone' => 'danger',
                'href' => $id > 0 ? '/sales/deals/' . $id : '/sales/deals?risk=high',
            ];
        }

        $nextActions = [];
        foreach ($this->list($today['overdue'] ?? null) as $item) {
            if (count($nextActions) >= 8) {
                break;
            }
            $nextActions[] = $this->nextAction($item, 'danger');
        }
        foreach ($this->list($today['must_do'] ?? null) as $item) {
            if (count($nextActions) >= 8) {
                break;
            }
            $nextActions[] = $this->nextAction($item, 'neutral');
        }

        $newLeads = [];
        foreach (array_slice($this->list($sales['new_leads'] ?? null), 0, 8) as $lead) {
            $id = (int) ($lead['id'] ?? 0);
            $status = strtolower((string) ($lead['status'] ?? 'new'));
            $newLeads[] = [
                'title' => (string) ($lead['name'] ?? ('Lead #' . $id)),
                'subtitle' => (string) ($lead['source'] ?? 'Direct'),
                'meta' => (string) ($lead['owner_name'] ?? 'Unassigned'),
                'status' => $status,
                'tone' => match ($status) {
                    'qualified' => 'positive',
                    'disqualified' => 'danger',
                    default => 'neutral',
                },
                'href' => $id > 0 ? '/sales/leads/' . $id : '/sales/leads',
            ];
        }

        return new SalesDashboardViewModel(
            activeDeals: (string) (int) ($kpis['active_deals'] ?? 0),
            pipelineValue: $this->money($kpis['pipeline_value'] ?? 0),
            expectedRevenue: $this->money($kpis['expected_revenue'] ?? 0),
            dealsAtRisk: (string) (int) ($kpis['deals_at_risk'] ?? 0),
            atRisk: $atRisk,
            nextActions: $nextActions,
            newLeads: $newLeads,
            error: $error,
        );
    }

    /** @return array<string,mixed> */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string,mixed>> */
    private function list(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    private function nextAction(array $item, string $tone): array
    {
        $id = (int) ($item['id'] ?? 0);

        return [
            'title' => (string) ($item['customer'] ?? $item['title'] ?? 'Follow-up'),
            'copy' => (string) ($item['title'] ?? 'Sales action'),
            'due' => (string) ($item['next_contact_at'] ?? 'Без дати'),
            'tone' => $tone,
            'href' => $id > 0 ? '/sales/deals/' . $id : '/sales/today',
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ' ') . ' USD';
    }
}
