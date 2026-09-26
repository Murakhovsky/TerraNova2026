<?php

declare(strict_types=1);

namespace App\Web\Administration;

use App\Web\Administration\ViewModel\AdministrationAnalyticsGridViewModel;
use App\Web\Administration\ViewModel\AdministrationAnalyticsViewModel;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridState;

final class AdministrationAnalyticsPresenter
{
    /** @param array<string,mixed> $report */
    public function present(array $report, int $requestedDays, ?string $error = null): AdministrationAnalyticsViewModel
    {
        $summary = $this->array($report['summary'] ?? null);
        $dailyRows = [];
        foreach ($this->list($report['daily'] ?? null) as $row) {
            $dailyRows[] = [
                'date' => (string) ($row['event_date'] ?? ''),
                'views' => (string) (int) ($row['property_views'] ?? 0),
                'cta' => (string) (int) ($row['cta'] ?? 0),
                'leads' => (string) (int) ($row['leads'] ?? 0),
            ];
        }

        $propertyRows = [];
        foreach ($this->list($report['top_properties'] ?? null) as $row) {
            $views = (int) ($row['property_views'] ?? 0);
            $leads = (int) ($row['leads'] ?? 0);
            $slug = trim((string) ($row['slug'] ?? ''));
            $propertyRows[] = [
                '_href' => $slug !== '' ? '/property/show/' . $slug : null,
                'property' => trim((string) ($row['public_id'] ?? '') . ' / ' . (string) ($row['title'] ?? ''), ' /'),
                'views' => (string) $views,
                'cta' => (string) (int) ($row['cta'] ?? 0),
                'pdf' => (string) (int) ($row['presentation_downloads'] ?? 0),
                'shares' => (string) (int) ($row['presentation_shares'] ?? 0),
                'leads' => (string) $leads,
                'conversion' => $views > 0 ? number_format($leads * 100 / $views, 1) . '%' : '—',
            ];
        }

        $sources = [];
        foreach ($this->list($report['sources'] ?? null) as $row) {
            $sources[] = [
                'title' => (string) (($row['source'] ?? '') ?: 'Direct'),
                'subtitle' => (string) (int) ($row['events'] ?? 0) . ' events',
                'meta' => (string) (int) ($row['leads'] ?? 0) . ' leads',
            ];
        }

        return new AdministrationAnalyticsViewModel(
            days: max(7, min(365, (int) ($report['days'] ?? $requestedDays))),
            kpis: [
                ['label' => 'Перегляди карток', 'value' => (string) (int) ($summary['property_views'] ?? 0), 'hint' => 'за період'],
                ['label' => 'Контактні CTA', 'value' => (string) (int) ($summary['cta'] ?? 0), 'hint' => (string) ($summary['view_to_cta'] ?? 0) . '% від переглядів'],
                ['label' => 'Заявки', 'value' => (string) (int) ($summary['leads'] ?? 0), 'hint' => (string) ($summary['view_to_lead'] ?? 0) . '% від переглядів'],
                ['label' => 'Презентації', 'value' => (string) (int) ($summary['presentation_downloads'] ?? 0), 'hint' => (string) (int) ($summary['presentation_shares'] ?? 0) . ' shares'],
            ],
            dailyGrid: $this->grid($dailyRows, [
                new DataGridColumn('date', 'Дата', mobilePriority: 10),
                new DataGridColumn('views', 'Перегляди', mobilePriority: 20, align: 'end'),
                new DataGridColumn('cta', 'CTA', mobilePriority: 30, align: 'end'),
                new DataGridColumn('leads', 'Заявки', mobilePriority: 40, align: 'end'),
            ]),
            propertyGrid: $this->grid($propertyRows, [
                new DataGridColumn('property', 'Об’єкт', mobilePriority: 10),
                new DataGridColumn('views', 'Перегляди', mobilePriority: 20, align: 'end'),
                new DataGridColumn('cta', 'CTA', mobilePriority: 30, align: 'end'),
                new DataGridColumn('pdf', 'PDF', mobilePriority: 40, align: 'end'),
                new DataGridColumn('shares', 'Надіслано', mobilePriority: 50, align: 'end'),
                new DataGridColumn('leads', 'Заявки', mobilePriority: 60, align: 'end'),
                new DataGridColumn('conversion', 'Конверсія', mobilePriority: 70, align: 'end'),
            ]),
            sources: $sources,
            error: $error,
        );
    }

    /** @param list<array<string,mixed>> $rows @param list<DataGridColumn> $columns */
    private function grid(array $rows, array $columns): AdministrationAnalyticsGridViewModel
    {
        $count = count($rows);
        $perPage = max(1, $count);

        return new AdministrationAnalyticsGridViewModel(
            query: new DataGridQuery(perPage: max(10, $count)),
            page: new DataGridPage($rows, $count, 1, $perPage),
            columns: $columns,
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
        if (!is_array($value)) return [];
        return array_values(array_filter($value, 'is_array'));
    }
}
