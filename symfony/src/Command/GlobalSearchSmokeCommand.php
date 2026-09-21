<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\WebExtensionProviderRegistry;
use App\Web\Experience\Search\GlobalSearchService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:search:smoke',
    description: 'Validate canonical COS global search and command palette runtime.',
)]
final class GlobalSearchSmokeCommand extends Command
{
    public function __construct(
        private readonly GlobalSearchService $search,
        private readonly WebExtensionProviderRegistry $providers,
        private readonly RouterInterface $router,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $route = $this->router->getRouteCollection()->get('cos_web_global_search');
        if ($route === null || $route->getPath() !== '/workspace/search') {
            $output->writeln('<error>Canonical global search route is unavailable.</error>');

            return Command::FAILURE;
        }

        $context = new WebExtensionContext(
            organizationId: 'default',
            role: 'admin',
            surface: 'workspace',
        );

        $providerIds = array_map(
            static fn($provider): string => $provider->serviceId(),
            $this->providers->forContext($context)->search(),
        );
        sort($providerIds, SORT_STRING);

        if ($providerIds !== [
            'diagnosticNavigationContributor',
            'propertyNavigationContributor',
            'salesNavigationContributor',
        ]) {
            $output->writeln('<error>Unexpected active global search provider set.</error>');

            return Command::FAILURE;
        }

        foreach ([
            'pipeline' => ['/sales/pipeline', 'Sales Pipeline'],
            'spatial' => ['/spatial/manage', 'Spatial Workspace'],
            'methodology' => ['/admin/diagnostics/methodology-studio', 'Methodology Studio'],
        ] as $query => [$expectedPath, $expectedLabel]) {
            $results = $this->search->search($context, $query, 20);
            $found = false;

            foreach ($results->items as $item) {
                if ($item->path === $expectedPath && str_contains($item->label, $expectedLabel)) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $output->writeln(sprintf(
                    '<error>Global search query "%s" did not resolve %s.</error>',
                    $query,
                    $expectedPath,
                ));

                return Command::FAILURE;
            }
        }

        $commands = $this->search->search($context, '', 20);
        $commandIds = array_map(static fn($item): string => $item->id, $commands->items);
        if (!in_array('command:core.home', $commandIds, true)
            || !in_array('command:sales.open', $commandIds, true)
        ) {
            $output->writeln('<error>Empty global query did not return canonical core/module commands.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/search/results_frame.html.twig', [
            'results' => $this->search->search($context, 'property', 20),
        ]);

        foreach ([
            '<turbo-frame',
            'id="cos-global-search-results"',
            'Global search results',
            'data-workspace-shell-target="resultItem"',
            'Property Inventory',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing global search render marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS global search runtime passed.');

        return Command::SUCCESS;
    }
}
