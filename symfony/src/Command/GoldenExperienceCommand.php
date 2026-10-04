<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Golden\GoldenExperienceSet;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:golden',
    description: 'Report and gate the eight COS Golden Experience reference pages.',
)]
final class GoldenExperienceCommand extends Command
{
    public function __construct(private readonly GoldenExperienceSet $golden)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('strict', null, InputOption::VALUE_NONE, 'Fail unless all Golden 8 pages are V1_READY at reference quality with human approval.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->golden->report();

        $io->title('COS Golden Experience');
        $io->definitionList(
            ['Required' => $report->required],
            ['Registered' => $report->registered],
            ['Implemented+' => $report->implemented],
            ['Golden ready' => $report->ready],
            ['Mass migration' => $report->massMigrationBlocked ? 'BLOCKED' : 'UNBLOCKED'],
        );

        $rows = [];
        foreach ($report->pages as $page) {
            $rows[] = [
                $page['id'],
                $page['path'],
                $page['archetype'],
                $page['status'],
                min($page['quality']),
                $page['humanAccepted'] ? 'yes' : 'no',
                $page['ready'] ? 'READY' : 'NOT READY',
            ];
        }

        $io->table(['Page', 'Path', 'Archetype', 'Status', 'Min quality', 'Human', 'Golden'], $rows);

        if ($report->missing !== []) {
            $io->error('Missing Golden contracts: ' . implode(', ', $report->missing));
        }

        if ($report->massMigrationBlocked) {
            $io->warning('EX-005 mass migration remains blocked until Golden 8 is complete.');
        }

        return $input->getOption('strict') && !$report->isComplete()
            ? Command::FAILURE
            : Command::SUCCESS;
    }
}
