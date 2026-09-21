<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Data\DataGridCsvExporter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class DataGridCatalogExportController
{
    public function __construct(
        private DataGridCatalogDemo $demo,
        private DataGridCsvExporter $exporter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $params = $request->query->all();
        $params['page'] = 1;
        $params['per_page'] = 200;

        $demoRequest = Request::create('/dev/ui', 'GET', $params);
        $data = $this->demo->build($demoRequest);

        $columns = array_values(array_filter(
            $data['columns'],
            static fn ($column): bool => $data['query']->visibleColumns === []
                ? $column->defaultVisible
                : in_array($column->key, $data['query']->visibleColumns, true),
        ));

        $csv = $this->exporter->export($columns, $data['page']->rows);

        return new Response($csv, Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cos-data-grid-demo.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
