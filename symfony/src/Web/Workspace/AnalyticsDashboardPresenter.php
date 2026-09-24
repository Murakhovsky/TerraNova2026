<?php

declare(strict_types=1);

namespace App\Web\Workspace;

use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;
use App\Web\Workspace\ViewModel\AnalyticsDashboardViewModel;
use App\Web\Workspace\ViewModel\AnalyticsGridViewModel;

final class AnalyticsDashboardPresenter
{
    /** @param array<string,mixed> $report */
    public function present(array $report, int $days, ?string $error = null): AnalyticsDashboardViewModel
    {
        $summary = $this->array($report['summary'] ?? null);
        $daily = $this->list($report['daily'] ?? null);
        $topProperties = $this->list($report['top_properties'] ?? null);
        $sources = $this->list($report['sources'] ?? null);

        $dailyRows = [];
        foreach ($daily as $row) {
            $dailyRows[] = [
                'date' => (string) ($row['event_date'] ?? ''),
                'views' => (string) (int) ($row['property_views'] ?? 0),
                'cta' => (string) (int) ($row['cta'] ?? 0),
                'leads' => (string) (int) ($row['leads'] ?? 0),
            ];
        }

        $sourceItems = [];
        foreach ($sources as $row) {
            $source = trim((string) ($row['source'] ?? ''));
            $sourceItems[] = [
                'title' => $source !== '' ? $source : 'Direct / unknown',
                'subtitle' => (int) ($row['events'] ?? 0) . ' events',
                'meta' => (int) ($row['leads'] ?? 0) . ' leads',
            ];
        }

        $propertyItems = [];
        foreach ($topProperties as $row) {
            $views = (int) ($row['property_views'] ?? 0);
            $leads = (int) ($row['leads'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            $identity = trim((string) ($row['public_id'] ?? '') . ' / ' . (string) ($row['title'] ?? ''), ' /');
            $propertyItems[] = [
                'title' => $identity !== '' ? $identity : 'Property',
                'subtitle' => sprintf(
                    '%d views · %d CTA · %d PDF · %d shares',
                    $views,
                    (int) ($row['cta'] ?? 0),
                    (int) ($row['presentation_downloads'] ?? 0),
                    (int) ($row['presentation_shares'] ?? 0),
                ),
                'meta' => $leads . ' leads · ' . ($views > 0 ? number_format($leads * 100 / $views, 1, '.', ' ') . '%' : '—'),
                'href' => $slug !== '' ? '/property/show/' . rawurlencode($slug) : '/property/catalog',
            ];
        }

        return new AnalyticsDashboardViewModel(
            days: max(7, min(365, (int) ($report['days'] ?? $days))),
            kpis: [
                [
                    'label' => 'Перегляди карток',
                    'value' => (string) (int) ($summary['property_views'] ?? 0),
                    'hint' => 'за період',
                    'href' => null,
                ],
                [
                    'label' => 'Контактні CTA',
                    'value' => (string) (int) ($summary['cta'] ?? 0),
                    'hint' => (string) ($summary['view_to_cta'] ?? 0) . '% від переглядів',
                    'href' => null,
                ],
                [
                    'label' => 'Заявки',
                    'value' => (string) (int) ($summary['leads'] ?? 0),
                    'hint' => (string) ($summary['view_to_lead'] ?? 0) . '% від переглядів',
                    'href' => '/client-case/inbox',
                ],
                [
                    'label' => 'Презентації',
                    'value' => (string) (int) ($summary['presentation_downloads'] ?? 0),
                    'hint' => (int) ($summary['presentation_shares'] ?? 0) . ' підготовлено до надсилання',
                    'href' => null,
                ],
            ],
            daily: $this->dailyGrid($dailyRows),
            sources: $sourceItems,
            properties: $propertyItems,
            error: $error,
        );
    }

    /** @param list<array<string,mixed>> $rows */
    private function dailyGrid(array $rows): AnalyticsGridViewModel
    {
        $count = count($rows);

        return new AnalyticsGridViewModel(
            query: new DataGridQuery(perPage: max(10, $count)),
            page: new DataGridPage($rows, $count, 1, max(1, $count)),
            columns: [
                new DataGridColumn('date', 'Дата', mobilePriority: 10),
                new DataGridColumn('views', 'Перегляди', mobilePriority: 20, align: 'end'),
                new DataGridColumn('cta', 'CTA', mobilePriority: 30, align: 'end'),
                new DataGridColumn('leads', 'Заявки', mobilePriority: 40, align: 'end'),
            ],
            state: $count === 0 ? DataGridState::Empty : DataGridState::Ready,
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
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }
}
