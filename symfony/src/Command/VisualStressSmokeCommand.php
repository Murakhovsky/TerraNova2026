<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Dev\DataGridCatalogDemo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;

#[AsCommand(name: 'cos:web:visual-stress:smoke')]
final class VisualStressSmokeCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly DataGridCatalogDemo $dataGrid,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $html = $this->twig->render('experience/visual_stress.html.twig', [
            'dataGridDemo' => $this->dataGrid->build(Request::create('/dev/stress')),
        ]);

        foreach ([
            'Canonical Stress Screens',
            'data-stress-screen="company-home"',
            'data-stress-screen="data-grid"',
            'data-stress-screen="entity-workspace"',
            'data-stress-screen="control-ai"',
            'Riverside Residence — enterprise rollout',
            'COS Control / AI / Operations',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln('<error>Missing stress-screen marker: ' . $marker . '</error>');

                return Command::FAILURE;
            }
        }

        $output->writeln('<info>Visual stress screens rendered successfully.</info>');

        return Command::SUCCESS;
    }
}
