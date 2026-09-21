<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Data\DataGridCsvExporter;
use App\Web\Experience\Dev\DataGridCatalogDemo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:data:smoke',
    description: 'Validate the canonical COS Data Platform runtime.',
)]
final class DataPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly DataGridCatalogDemo $demo,
        private readonly DataGridCsvExporter $csv,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $request = Request::create('/dev/ui', 'GET', [
            'q' => 'Northstar',
            'sort' => 'name',
            'dir' => 'desc',
            'filter' => ['status' => 'active'],
            'columns' => ['name', 'status', 'owner'],
            'per_page' => 10,
        ]);

        $demo = $this->demo->build($request);

        if ($demo['query']->search !== 'Northstar' || $demo['query']->direction !== 'desc') {
            $output->writeln('<error>DataGrid URL state was not normalized.</error>');

            return Command::FAILURE;
        }

        if ($demo['page']->total !== 1 || count($demo['page']->rows) !== 1) {
            $output->writeln('<error>DataGrid server-side search/filter pipeline failed.</error>');

            return Command::FAILURE;
        }

        $csv = $this->csv->export(
            array_slice($demo['columns'], 0, 2),
            $demo['page']->rows,
        );

        foreach (['Account', 'Status', 'Northstar Realty'] as $marker) {
            if (!str_contains($csv, $marker)) {
                $output->writeln(sprintf('<error>DataGrid CSV export is missing: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $html = $this->twig->render('experience/design_system_catalog.html.twig', [
            'dataGridDemo' => $demo,
        ]);

        foreach ([
            'Data Platform / DataGrid',
            'id="catalog-data-grid"',
            'data-controller="data-grid"',
            'cos-data-grid__table',
            'cos-data-grid__mobile',
            'Export CSV',
            'data-action="click->data-grid#bulkAction"',
            'aria-sort=',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing Data Platform marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Data Platform runtime passed.');

        return Command::SUCCESS;
    }
}
