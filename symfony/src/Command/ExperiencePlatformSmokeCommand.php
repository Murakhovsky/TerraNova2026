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
    name: 'cos:web:experience:smoke',
    description: 'Validate the canonical Symfony Experience Platform runtime.',
)]
final class ExperiencePlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $html = $this->twig->render('experience/runtime_smoke.html.twig');

        foreach ([
            'data-wave12-experience-smoke',
            'data-experience-runtime="twig-component"',
            'twig-component',
            '@hotwired/stimulus',
            '@hotwired/turbo',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing Experience runtime marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        if ($this->twig->getFunction('importmap') === null) {
            $output->writeln('<error>AssetMapper importmap() Twig function is unavailable.</error>');

            return Command::FAILURE;
        }

        if ($this->router->getRouteCollection()->get('ux_live_component') === null) {
            $output->writeln('<error>Live Component route is unavailable.</error>');

            return Command::FAILURE;
        }

        $output->writeln('Symfony Experience Platform runtime passed.');

        return Command::SUCCESS;
    }
}
