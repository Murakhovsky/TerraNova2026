<?php

declare(strict_types=1);

namespace App\Web\Workspace;

use App\Web\Workspace\ViewModel\ExecutiveDashboardViewModel;

final class ExecutiveDashboardPresenter
{
    /** @param array<string,mixed> $snapshot */
    public function present(array $snapshot, ?string $error = null): ExecutiveDashboardViewModel
    {
        $sales = $this->array($snapshot['sales'] ?? null);
        $property = $this->array($snapshot['property'] ?? null);
        $cos = $this->array($snapshot['cos'] ?? null);
        $modules = $this->array($snapshot['modules'] ?? null);

        $salesData = $this->array($sales['data'] ?? null);
        $propertyData = $this->array($property['data'] ?? null);
        $cosData = $this->array($cos['data'] ?? null);
        $cosOverview = $this->array($cosData['overview'] ?? null);
        $cosStats = $this->array($cosOverview['stats'] ?? null);
        $cosHealth = $this->array($cosData['health'] ?? null);
        $salesKpis = $this->array($salesData['kpis'] ?? null);
        $salesToday = $this->array($salesData['today'] ?? null);

        $metrics = [];
        if (($sales['enabled'] ?? false) && ($sales['available'] ?? false)) {
            $metrics[] = $this->metric('Активні угоди', (string) (int) ($salesKpis['active_deals'] ?? 0), 'Sales pipeline', '/sales/deals');
            $metrics[] = $this->metric('Expected revenue', $this->money($salesKpis['expected_revenue'] ?? 0), 'За поточними ймовірностями', '/sales/dashboard');
            $metrics[] = $this->metric('У ризику', (string) (int) ($salesKpis['deals_at_risk'] ?? 0), 'Потребують уваги', '/sales/deals?risk=high');
        }
        if (($property['enabled'] ?? false) && ($property['available'] ?? false)) {
            $metrics[] = $this->metric('Активний каталог', (string) (int) ($propertyData['catalog_total'] ?? 0), 'Published + active', '/property/manage');
        }
        if ($cos['available'] ?? false) {
            $metrics[] = $this->metric('Approvals', (string) (int) ($cosStats['pending_approvals'] ?? 0), 'Очікують рішення', '/cos/control-center');
            $metrics[] = $this->metric('COS health', strtoupper((string) ($cosHealth['status'] ?? 'unknown')), 'Runtime health', '/cos/control-center');
        }

        $salesAttention = ($sales['available'] ?? false) ? [
            $this->attention('Прострочені follow-ups', (int) ($salesKpis['followups_overdue'] ?? 0), '/sales/today'),
            $this->attention('Must do сьогодні', count($this->list($salesToday['must_do'] ?? null)), '/sales/today'),
            $this->attention('Нові відповіді', count($this->list($salesToday['new_replies'] ?? null)), '/sales/today'),
            $this->attention('AI recommendations', count($this->list($salesToday['ai_recommended'] ?? null)), '/sales/today'),
        ] : [];

        $cosAttention = ($cos['available'] ?? false) ? [
            $this->attention('Open actions', (int) ($cosStats['open_actions'] ?? 0), '/cos/control-center'),
            $this->attention('Pending approvals', (int) ($cosStats['pending_approvals'] ?? 0), '/cos/control-center'),
            $this->attention('Dead jobs', (int) ($cosStats['dead_jobs'] ?? 0), '/cos/control-center'),
            $this->attention('Failed events', (int) ($cosStats['failed_events'] ?? 0), '/cos/control-center'),
        ] : [];

        $leads = [];
        foreach (array_slice($this->list($salesData['new_leads'] ?? null), 0, 6) as $lead) {
            $id = (int) ($lead['id'] ?? 0);
            $dealId = (int) ($lead['deal_id'] ?? 0);
            $leads[] = [
                'title' => (string) ($lead['name'] ?? 'Lead'),
                'subtitle' => (string) (($lead['source'] ?? 'Без джерела') . ' · ' . ($lead['owner_name'] ?? 'Unassigned')),
                'meta' => (string) ($lead['ai_priority'] ?? 'NORMAL'),
                'href' => $dealId > 0 ? '/sales/deals/' . $dealId : ($id > 0 ? '/sales/leads/' . $id : '/sales/leads'),
            ];
        }

        $properties = [];
        foreach (array_slice($this->list($propertyData['featured'] ?? null), 0, 6) as $item) {
            $slug = trim((string) ($item['slug'] ?? ''));
            $properties[] = [
                'title' => (string) ($item['title'] ?? 'Об’єкт'),
                'subtitle' => (string) (($item['city'] ?? '—') . ' · ' . ($item['type_name'] ?? 'Нерухомість')),
                'meta' => (string) ($item['status'] ?? ''),
                'href' => $slug !== '' ? '/property/show/' . rawurlencode($slug) : '/property/catalog',
            ];
        }

        $moduleItems = [];
        foreach ($this->list($modules['items'] ?? null) as $module) {
            $enabled = (bool) ($module['enabled'] ?? false);
            $moduleItems[] = [
                'title' => (string) ($module['name'] ?? $module['id'] ?? 'Module'),
                'subtitle' => (string) ($module['description'] ?? ''),
                'status' => $enabled ? 'ON' : 'OFF',
                'tone' => $enabled ? 'positive' : 'neutral',
                'meta' => 'v' . (string) ($module['version'] ?? '—'),
            ];
        }

        $pending = array_values(array_filter(
            $this->list($cosOverview['approvals'] ?? null),
            static fn (array $item): bool => strtoupper((string) ($item['status'] ?? '')) === 'PENDING',
        ));
        $open = array_values(array_filter(
            $this->list($cosOverview['actions'] ?? null),
            static fn (array $item): bool => !in_array(
                strtoupper((string) ($item['status'] ?? '')),
                ['COMPLETED', 'REJECTED'],
                true,
            ),
        ));

        $decisions = [];
        foreach (array_slice([...$pending, ...$open], 0, 8) as $item) {
            $status = (string) ($item['status'] ?? $item['approval_status'] ?? 'PENDING');
            $risk = strtoupper((string) ($item['risk_level'] ?? ''));
            $decisions[] = [
                'title' => (string) ($item['action_type'] ?? $item['type'] ?? 'Action'),
                'subtitle' => (string) (($item['target_type'] ?? '—') . ' / ' . ($item['target_id'] ?? '—')),
                'meta' => $risk !== '' ? $risk : $status,
                'status' => $status,
                'tone' => $this->tone($status, $risk),
                'href' => '/cos/control-center',
            ];
        }

        return new ExecutiveDashboardViewModel(
            generatedAt: (string) ($snapshot['generated_at'] ?? '—'),
            metrics: $metrics,
            salesAttention: $salesAttention,
            cosAttention: $cosAttention,
            leads: $leads,
            properties: $properties,
            propertySummary: [
                'published' => (int) ($propertyData['published'] ?? 0),
                'active' => (int) ($propertyData['active'] ?? 0),
            ],
            modules: $moduleItems,
            decisions: $decisions,
            sectionStates: [
                'sales' => $this->sectionState($sales, 'Sales вимкнено', 'Sales тимчасово недоступний'),
                'property' => $this->sectionState($property, 'Property вимкнено', 'Property тимчасово недоступний'),
                'cos' => $this->sectionState($cos, 'COS недоступний', 'COS read model тимчасово недоступний'),
                'modules' => ($modules['available'] ?? false)
                    ? ['state' => 'normal', 'title' => '', 'copy' => '']
                    : ['state' => 'error', 'title' => 'Стан модулів не прочитано', 'copy' => (string) ($modules['error'] ?? 'Module resolver недоступний.')],
            ],
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

    /** @return array<string,mixed> */
    private function metric(string $label, string $value, string $hint, string $href): array
    {
        return compact('label', 'value', 'hint', 'href');
    }

    /** @return array<string,mixed> */
    private function attention(string $label, int $value, string $href): array
    {
        return ['label' => $label, 'value' => $value, 'href' => $href];
    }

    /** @return array{state:string,title:string,copy:string} */
    private function sectionState(array $section, string $disabledTitle, string $errorTitle): array
    {
        if (!($section['enabled'] ?? false)) {
            return ['state' => 'disabled', 'title' => $disabledTitle, 'copy' => 'Модуль не виконує запити, доки його вимкнено.'];
        }

        if (!($section['available'] ?? false)) {
            return ['state' => 'error', 'title' => $errorTitle, 'copy' => (string) ($section['error'] ?? 'Дані тимчасово недоступні.')];
        }

        return ['state' => 'normal', 'title' => '', 'copy' => ''];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 0, '.', ' ') . ' USD';
    }

    private function tone(string $status, string $risk): string
    {
        if ($risk === 'HIGH' || $risk === 'CRITICAL') {
            return 'danger';
        }
        if ($risk === 'MEDIUM') {
            return 'warning';
        }

        return match (strtoupper(trim($status))) {
            'APPROVED', 'COMPLETED', 'EXECUTED', 'SUCCESS' => 'positive',
            'FAILED', 'REJECTED', 'DENIED', 'ERROR' => 'danger',
            'PENDING', 'PENDING_APPROVAL', 'QUEUED' => 'warning',
            default => 'neutral',
        };
    }
}
