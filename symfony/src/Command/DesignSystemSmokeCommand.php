<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:design-system:smoke',
    description: 'Validate the canonical COS Design System runtime.',
)]
final class DesignSystemSmokeCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $route = $this->router->getRouteCollection()->get('cos_web_design_system_catalog');
        if ($route === null || $route->getPath() !== '/dev/ui') {
            $output->writeln('<error>Design System catalog route is unavailable.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/design_system_catalog.html.twig');

        foreach ([
            'COS Experience Platform',
            'data-controller="appearance"',
            'cos-button--primary',
            'cos-badge--positive',
            'cos-empty-state',
            'Semantic color tokens',
            'PHASE 6 Canonical Primitives Pass',
            'PHASE 7 Entity + Business UX',
            'cos-entity-header',
            'cos-status--positive',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing Design System marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Design System runtime passed.');

        return Command::SUCCESS;
    }
}
