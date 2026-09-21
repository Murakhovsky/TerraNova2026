<?php

declare(strict_types=1);

namespace App\Web\Experience\Component;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridSavedView;
use App\Web\Experience\Data\DataGridState;
use App\Web\Experience\Data\DataGridUrlBuilder;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(
    name: 'CosDataGrid',
    template: 'components/experience/cos_data_grid.html.twig',
)]
final class CosDataGrid
{
    public string $id = 'cos-data-grid';
    public string $label = 'Data';
    public string $baseUrl = '';
    public string $rowIdKey = 'id';
    public bool $selectable = false;
    public ?string $exportUrl = null;
    public ?string $errorMessage = null;

    public DataGridQuery $query;
    public DataGridPage $page;
    public DataGridState $state = DataGridState::Ready;

    /** @var list<DataGridColumn> */
    public array $columns = [];

    /** @var list<DataGridFilter> */
    public array $filters = [];

    /** @var list<DataGridSavedView> */
    public array $savedViews = [];

    /** @var list<UIAction> */
    public array $rowActions = [];

    /** @var list<UIAction> */
    public array $bulkActions = [];

    /** @return list<DataGridColumn> */
    public function visibleColumns(): array
    {
        if ($this->query->visibleColumns === []) {
            return array_values(array_filter(
                $this->columns,
                static fn (DataGridColumn $column): bool => $column->defaultVisible,
            ));
        }

        $visible = array_fill_keys($this->query->visibleColumns, true);

        return array_values(array_filter(
            $this->columns,
            static fn (DataGridColumn $column): bool => isset($visible[$column->key]),
        ));
    }

    /** @return list<DataGridColumn> */
    public function mobileColumns(): array
    {
        $columns = $this->visibleColumns();

        usort(
            $columns,
            static fn (DataGridColumn $left, DataGridColumn $right): int => $left->mobilePriority <=> $right->mobilePriority,
        );

        return $columns;
    }

    public function isColumnVisible(DataGridColumn $column): bool
    {
        if ($this->query->visibleColumns === []) {
            return $column->defaultVisible;
        }

        return in_array($column->key, $this->query->visibleColumns, true);
    }

    public function value(array $row, string $key): string
    {
        $value = $row;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return '';
            }

            $value = $value[$segment];
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    public function rowId(array $row): string
    {
        $value = $row[$this->rowIdKey] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<string,mixed> $overrides */
    public function url(array $overrides = []): string
    {
        return DataGridUrlBuilder::build($this->baseUrl, $this->query, $overrides);
    }

    public function savedViewUrl(DataGridSavedView $view): string
    {
        return DataGridUrlBuilder::build($this->baseUrl, $view->query, ['view' => $view->id]);
    }

    public function sortUrl(DataGridColumn $column): string
    {
        $direction = $this->query->sort === $column->key && $this->query->direction === 'asc'
            ? 'desc'
            : 'asc';

        return $this->url([
            'sort' => $column->key,
            'direction' => $direction,
            'page' => 1,
        ]);
    }

    public function exportLink(string $format = 'csv'): ?string
    {
        if ($this->exportUrl === null || $this->exportUrl === '') {
            return null;
        }

        $query = $this->query->parameters();
        $query['format'] = $format;

        return $this->exportUrl . (str_contains($this->exportUrl, '?') ? '&' : '?') . http_build_query($query);
    }

    public function isEmpty(): bool
    {
        return $this->state === DataGridState::Empty || ($this->state === DataGridState::Ready && $this->page->total === 0);
    }
}
