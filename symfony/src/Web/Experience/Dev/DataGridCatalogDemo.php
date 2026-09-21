<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Data\DataGridColumn;
use App\Web\Experience\Data\DataGridFilter;
use App\Web\Experience\Data\DataGridPage;
use App\Web\Experience\Data\DataGridQuery;
use App\Web\Experience\Data\DataGridSavedView;
use App\Web\Experience\Data\DataGridState;
use Symfony\Component\HttpFoundation\Request;

final class DataGridCatalogDemo
{
    /** @return array<string,mixed> */
    public function build(Request $request): array
    {
        $query = DataGridQuery::fromArray($request->query->all());

        $columns = [
            new DataGridColumn('name', 'Account', sortable: true, filterable: true, mobilePriority: 10),
            new DataGridColumn('status', 'Status', sortable: true, filterable: true, mobilePriority: 20),
            new DataGridColumn('owner', 'Owner', sortable: true, mobilePriority: 30),
            new DataGridColumn('value', 'Value', sortable: true, mobilePriority: 40, align: 'end'),
            new DataGridColumn('updated', 'Updated', sortable: true, mobilePriority: 50),
            new DataGridColumn('region', 'Region', defaultVisible: false, mobilePriority: 60),
        ];

        $rows = [
            ['id' => 'AC-1001', 'name' => 'Northstar Realty', 'status' => 'active', 'owner' => 'Marta', 'value' => '$84k', 'updated' => 'Today', 'region' => 'Lviv'],
            ['id' => 'AC-1002', 'name' => 'Harbor Projects', 'status' => 'attention', 'owner' => 'Oleh', 'value' => '$61k', 'updated' => 'Today', 'region' => 'Kyiv'],
            ['id' => 'AC-1003', 'name' => 'Atlas Property Group', 'status' => 'active', 'owner' => 'Iryna', 'value' => '$53k', 'updated' => 'Yesterday', 'region' => 'Warsaw'],
            ['id' => 'AC-1004', 'name' => 'Urban Field', 'status' => 'paused', 'owner' => 'Marta', 'value' => '$42k', 'updated' => '2 days ago', 'region' => 'Lviv'],
            ['id' => 'AC-1005', 'name' => 'Cedar Homes', 'status' => 'attention', 'owner' => 'Andrii', 'value' => '$37k', 'updated' => '2 days ago', 'region' => 'Prague'],
            ['id' => 'AC-1006', 'name' => 'Vector Estates', 'status' => 'active', 'owner' => 'Oleh', 'value' => '$35k', 'updated' => '3 days ago', 'region' => 'Kyiv'],
            ['id' => 'AC-1007', 'name' => 'Bridge & Brick', 'status' => 'active', 'owner' => 'Iryna', 'value' => '$31k', 'updated' => '3 days ago', 'region' => 'Krakow'],
            ['id' => 'AC-1008', 'name' => 'Westline', 'status' => 'paused', 'owner' => 'Andrii', 'value' => '$29k', 'updated' => '4 days ago', 'region' => 'Lviv'],
            ['id' => 'AC-1009', 'name' => 'Prime Yard', 'status' => 'attention', 'owner' => 'Marta', 'value' => '$24k', 'updated' => '5 days ago', 'region' => 'Kyiv'],
            ['id' => 'AC-1010', 'name' => 'Stoneleaf', 'status' => 'active', 'owner' => 'Oleh', 'value' => '$19k', 'updated' => '6 days ago', 'region' => 'Lviv'],
            ['id' => 'AC-1011', 'name' => 'District One', 'status' => 'active', 'owner' => 'Iryna', 'value' => '$17k', 'updated' => '1 week ago', 'region' => 'Berlin'],
            ['id' => 'AC-1012', 'name' => 'Fieldhouse', 'status' => 'paused', 'owner' => 'Andrii', 'value' => '$14k', 'updated' => '1 week ago', 'region' => 'Lviv'],
        ];

        if ($query->search !== '') {
            $needle = mb_strtolower($query->search);
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => str_contains(
                    mb_strtolower(implode(' ', array_map('strval', $row))),
                    $needle,
                ),
            ));
        }

        $status = $query->filters['status'] ?? '';
        if ($status !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => $row['status'] === $status,
            ));
        }

        $sortable = ['name', 'status', 'owner', 'value', 'updated', 'region'];
        if ($query->sort !== null && in_array($query->sort, $sortable, true)) {
            $sort = $query->sort;
            $direction = $query->direction;

            usort($rows, static function (array $left, array $right) use ($sort, $direction): int {
                $result = strnatcasecmp((string) ($left[$sort] ?? ''), (string) ($right[$sort] ?? ''));

                return $direction === 'desc' ? -$result : $result;
            });
        }

        $total = count($rows);
        $pageCount = max(1, (int) ceil($total / $query->perPage));
        $pageNumber = min($query->page, $pageCount);
        if ($pageNumber !== $query->page) {
            $query = $query->with(['page' => $pageNumber]);
        }

        $pageRows = array_slice(
            $rows,
            ($query->page - 1) * $query->perPage,
            $query->perPage,
        );

        return [
            'query' => $query,
            'page' => new DataGridPage($pageRows, $total, $query->page, $query->perPage),
            'columns' => $columns,
            'filters' => [
                new DataGridFilter('status', 'Status', [
                    '' => 'All statuses',
                    'active' => 'Active',
                    'attention' => 'Needs attention',
                    'paused' => 'Paused',
                ], $status !== '' ? $status : null),
            ],
            'savedViews' => [
                new DataGridSavedView('all', 'All', new DataGridQuery(perPage: $query->perPage)),
                new DataGridSavedView('attention', 'Needs attention', new DataGridQuery(
                    perPage: $query->perPage,
                    filters: ['status' => 'attention'],
                )),
                new DataGridSavedView('active', 'Active', new DataGridQuery(
                    perPage: $query->perPage,
                    sort: 'updated',
                    direction: 'desc',
                    filters: ['status' => 'active'],
                )),
            ],
            'rowActions' => [
                new UIAction(
                    id: 'catalog.account.view',
                    label: 'Open',
                    intent: UIActionIntent::View,
                    placements: [UIActionPlacement::DATA_GRID_ROW],
                ),
                new UIAction(
                    id: 'catalog.account.edit',
                    label: 'Edit',
                    intent: UIActionIntent::Edit,
                    placements: [UIActionPlacement::DATA_GRID_ROW],
                ),
            ],
            'bulkActions' => [
                new UIAction(
                    id: 'catalog.account.archive',
                    label: 'Archive selected',
                    intent: UIActionIntent::Archive,
                    placements: [UIActionPlacement::DATA_GRID_BULK],
                ),
            ],
            'state' => $total === 0 ? DataGridState::Empty : DataGridState::Ready,
        ];
    }
}
