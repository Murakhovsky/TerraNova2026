<?php

declare(strict_types=1);

namespace App\Web\Experience\Data;

final readonly class DataGridQuery
{
    /**
     * @param array<string,string> $filters
     * @param list<string> $visibleColumns
     */
    public function __construct(
        public string $search = '',
        public int $page = 1,
        public int $perPage = 25,
        public ?string $sort = null,
        public string $direction = 'asc',
        public array $filters = [],
        public array $visibleColumns = [],
        public ?string $view = null,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromArray(array $input): self
    {
        $search = trim((string) ($input['q'] ?? $input['search'] ?? ''));
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(10, min(200, (int) ($input['per_page'] ?? 25)));

        $sort = trim((string) ($input['sort'] ?? ''));
        $sort = $sort !== '' ? $sort : null;

        $direction = strtolower(trim((string) ($input['dir'] ?? 'asc')));
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        $filters = [];
        foreach ((array) ($input['filter'] ?? []) as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);
            if ($normalized !== '') {
                $filters[(string) $key] = $normalized;
            }
        }

        $columns = $input['columns'] ?? [];
        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        $visibleColumns = [];
        foreach ((array) $columns as $column) {
            $column = trim((string) $column);
            if ($column !== '') {
                $visibleColumns[] = $column;
            }
        }

        $view = trim((string) ($input['view'] ?? ''));
        $view = $view !== '' ? $view : null;

        return new self(
            search: $search,
            page: $page,
            perPage: $perPage,
            sort: $sort,
            direction: $direction,
            filters: $filters,
            visibleColumns: array_values(array_unique($visibleColumns)),
            view: $view,
        );
    }

    /** @param array<string,mixed> $overrides */
    public function with(array $overrides): self
    {
        return new self(
            search: array_key_exists('search', $overrides) ? trim((string) $overrides['search']) : $this->search,
            page: array_key_exists('page', $overrides) ? max(1, (int) $overrides['page']) : $this->page,
            perPage: array_key_exists('perPage', $overrides) ? max(10, min(200, (int) $overrides['perPage'])) : $this->perPage,
            sort: array_key_exists('sort', $overrides) ? (($overrides['sort'] === null || trim((string) $overrides['sort']) === '') ? null : trim((string) $overrides['sort'])) : $this->sort,
            direction: array_key_exists('direction', $overrides)
                ? (strtolower((string) $overrides['direction']) === 'desc' ? 'desc' : 'asc')
                : $this->direction,
            filters: array_key_exists('filters', $overrides) ? (array) $overrides['filters'] : $this->filters,
            visibleColumns: array_key_exists('visibleColumns', $overrides) ? array_values((array) $overrides['visibleColumns']) : $this->visibleColumns,
            view: array_key_exists('view', $overrides) ? ($overrides['view'] !== null ? trim((string) $overrides['view']) : null) : $this->view,
        );
    }

    /** @return array<string,mixed> */
    public function parameters(): array
    {
        $params = [];

        if ($this->search !== '') {
            $params['q'] = $this->search;
        }

        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        if ($this->perPage !== 25) {
            $params['per_page'] = $this->perPage;
        }

        if ($this->sort !== null) {
            $params['sort'] = $this->sort;
            $params['dir'] = $this->direction;
        }

        if ($this->filters !== []) {
            $params['filter'] = $this->filters;
        }

        if ($this->visibleColumns !== []) {
            $params['columns'] = $this->visibleColumns;
        }

        if ($this->view !== null && $this->view !== '') {
            $params['view'] = $this->view;
        }

        return $params;
    }
}
