<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:interactions:smoke',
    description: 'Validate canonical COS interaction components runtime.',
)]
final class InteractionComponentsSmokeCommand extends Command
{
    public function __construct(private readonly Environment $twig)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $html = $this->twig->render('experience/design_system_catalog.html.twig');

        foreach ([
            'Interaction components',
            'id="catalog-modal"',
            'Modal content remains server-rendered Twig content.',
            'id="catalog-drawer"',
            'data-controller="dropdown"',
            'role="tablist"',
            'Overview panel',
            'id="catalog-popover"',
            'role="tooltip"',
            'data-controller="dialog confirm"',
            'data-confirm-step-up-value="true"',
            'cos-toast--positive',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing interaction runtime marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS interaction components runtime passed.');

        return Command::SUCCESS;
    }
}
