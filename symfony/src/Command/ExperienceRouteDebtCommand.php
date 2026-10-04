<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Release\ExperienceRouteDebtScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:routes:debt',
    description: 'Audit EX-007 stale/missing/mismatched Experience routes.',
)]
final class ExperienceRouteDebtCommand extends Command
{
    public function __construct(private readonly ExperienceRouteDebtScanner $scanner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail when route debt remains.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->scanner->scan();
        $io = new SymfonyStyle($input, $output);
        $io->title('COS Experience Route Debt');
        $io->definitionList(
            ['Missing routes' => count($report->missing)],
            ['Stale registry entries' => count($report->stale)],
            ['Path/ownership mismatches' => count($report->mismatched)],
        );

        foreach ([
            'Missing routes' => $report->missing,
            'Stale registry entries' => $report->stale,
            'Mismatches' => $report->mismatched,
        ] as $label => $items) {
            if ($items !== []) {
                $io->section($label);
                $io->listing($items);
            }
        }

        if ($report->isGreen()) {
            $io->success('Experience route graph is clean.');
        }

        return $input->getOption('strict') && !$report->isGreen()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
