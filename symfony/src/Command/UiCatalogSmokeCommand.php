<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Dev\UiCatalogRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:ui-catalog:smoke',
    description: 'Validate the Wave 12.22 internal component playground runtime.',
)]
final class UiCatalogSmokeCommand extends Command
{
    public function __construct(
        private readonly UiCatalogRegistry $registry,
        private readonly Environment $twig,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $entries = $this->registry->entries();
        if (count($entries) < 43) {
            $output->writeln('<error>UI Catalog registry is unexpectedly incomplete.</error>');

            return Command::FAILURE;
        }

        $route = $this->router->getRouteCollection()->get('cos_web_design_system_catalog');
        if ($route === null || $route->getPath() !== '/dev/ui') {
            $output->writeln('<error>Canonical UI Catalog route is unavailable.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/_ui_catalog_playground.html.twig', [
            'uiCatalog' => [
                'groups' => $this->registry->grouped(),
                'categories' => $this->registry->categories(),
                'stats' => $this->registry->stats(),
            ],
        ]);

        foreach ([
            'Internal component playground',
            'data-controller="ui-catalog"',
            'CosButton',
            'CosDataGrid',
            'CosWorkspace',
            'CosAgentRun',
            'Foundation specimens',
            'Feedback specimens',
            'Form control specimens',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing UI Catalog runtime marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln(sprintf(
            'COS UI Catalog runtime passed: %d components / %d categories.',
            count($entries),
            count($this->registry->categories()),
        ));

        return Command::SUCCESS;
    }
}
