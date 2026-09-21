<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\UX\Autocomplete\AutocompleteBundle;
use Symfony\UX\Chartjs\ChartjsBundle;
use Symfony\UX\Dropzone\DropzoneBundle;
use Symfony\UX\Icons\UXIconsBundle;
use Symfony\UX\Map\UXMapBundle;
use Symfony\UX\Toolkit\UXToolkitBundle;
use Symfony\UX\Translator\UxTranslatorBundle;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:toolkit:smoke',
    description: 'Validate the canonical COS Symfony UI Toolkit runtime.',
)]
final class UIToolkitSmokeCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly RouterInterface $router,
        private readonly Environment $twig,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bundles = $this->kernel->getBundles();

        foreach ([
            AutocompleteBundle::class,
            ChartjsBundle::class,
            DropzoneBundle::class,
            UXIconsBundle::class,
            UXMapBundle::class,
            UXToolkitBundle::class,
            UxTranslatorBundle::class,
        ] as $requiredBundle) {
            $found = false;

            foreach ($bundles as $bundle) {
                if ($bundle instanceof $requiredBundle) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $output->writeln(sprintf('<error>Missing UI Toolkit bundle: %s</error>', $requiredBundle));

                return Command::FAILURE;
            }
        }

        if ($this->router->getRouteCollection()->get('ux_entity_autocomplete') === null) {
            $output->writeln('<error>UX Autocomplete route is unavailable.</error>');

            return Command::FAILURE;
        }

        foreach (['ux_icon', 'render_chart', 'ux_map'] as $function) {
            if ($this->twig->getFunction($function) === null) {
                $output->writeln(sprintf('<error>Missing UI Toolkit Twig function: %s</error>', $function));

                return Command::FAILURE;
            }
        }

        $importmapPath = $this->kernel->getProjectDir() . '/importmap.php';
        $importmap = (string) file_get_contents($importmapPath);

        foreach ([
            "'bootstrap'",
            "'chart.js'",
            "'tabulator-tables'",
            "'fullcalendar'",
            "'sortablejs'",
            "'flatpickr'",
            "'cytoscape'",
            "'tom-select'",
            "'@symfony/ux-translator'",
            "'intl-messageformat'",
        ] as $specifier) {
            if (!str_contains($importmap, $specifier)) {
                $output->writeln(sprintf('<error>Missing browser import: %s</error>', $specifier));

                return Command::FAILURE;
            }
        }

        $output->writeln('Symfony UI Toolkit runtime passed.');

        return Command::SUCCESS;
    }
}
