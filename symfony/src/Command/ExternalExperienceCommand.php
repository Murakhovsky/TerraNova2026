<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\External\ExternalExperiencePlanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:external',
    description: 'Report EX-006 Portal/Public Experience inventory and enforce the external UX boundary.',
)]
final class ExternalExperienceCommand extends Command
{
    public function __construct(private readonly ExternalExperiencePlanner $planner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->planner->report();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('COS Experience EX-006 Portal/Public');
        $io->definitionList(
            ['External pages' => $report->pages],
            ['Public' => $report->surfaces['public']],
            ['Portal' => $report->surfaces['portal']],
            ['P0' => $report->p0],
            ['P1' => $report->p1],
            ['V1 ready' => $report->v1Ready],
            ['Design System' => 'shared'],
            ['UX purpose' => 'separate from internal Workspace UX'],
        );

        $rows = [];
        foreach ($report->domains as $domain => $count) {
            $rows[] = [$domain, $count];
        }
        $io->table(['Domain', 'Pages'], $rows);

        return Command::SUCCESS;
    }
}
