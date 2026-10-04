<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Migration\WorkspaceMigrationPlanner;
use App\Web\Experience\Migration\WorkspaceMigrationRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:migrate',
    description: 'Plan or start EX-005 Workspace Mass Migration through the governed PageDelivery workflow.',
)]
final class ExperienceWorkspaceMigrationCommand extends Command
{
    public function __construct(
        private readonly WorkspaceMigrationPlanner $planner,
        private readonly WorkspaceMigrationRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('start', null, InputOption::VALUE_NONE, 'Start a gated L3 migration batch.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum pages to start in one batch.', '1')
            ->addOption('stage', null, InputOption::VALUE_REQUIRED, 'Optional migration stage name.')
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization id.', 'default')
            ->addOption('correlation', null, InputOption::VALUE_REQUIRED, 'Correlation prefix.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $plan = $this->planner->plan();

        $io->title('COS Experience EX-005 Workspace Migration');
        $io->definitionList(
            ['State' => $plan->blocked ? 'BLOCKED' : 'READY'],
            ['Reason' => $plan->blockedReason !== '' ? $plan->blockedReason : 'Golden gate satisfied'],
            ['Pages queued' => $plan->pages],
            ['Autonomy' => 'L3'],
            ['Auto merge' => 'disabled'],
        );

        $rows = [];
        foreach ($plan->stages as $stage) {
            $rows[] = [
                $stage['sequence'],
                $stage['name'],
                $stage['count'],
                implode(', ', array_map(static fn (array $page): string => $page['page_id'], array_slice($stage['pages'], 0, 5))),
            ];
        }
        $io->table(['#', 'Stage', 'Pages', 'First candidates'], $rows);

        if (!$input->getOption('start')) {
            return Command::SUCCESS;
        }

        $correlation = trim((string) $input->getOption('correlation'));
        if ($correlation === '') {
            $correlation = 'experience-migration-'.bin2hex(random_bytes(6));
        }

        $started = $this->runner->start(
            (string) $input->getOption('organization'),
            $correlation,
            (int) $input->getOption('limit'),
            ($stage = trim((string) $input->getOption('stage'))) !== '' ? $stage : null,
        );

        $io->success(sprintf('Started %d autonomous L3 page deliveries.', count($started)));
        foreach ($started as $item) {
            $io->writeln(sprintf(
                '%s -> feature=%s workflow=%s',
                $item['page']['page_id'],
                $item['delivery']['feature_id'],
                $item['delivery']['workflow_id'],
            ));
        }

        return Command::SUCCESS;
    }
}
