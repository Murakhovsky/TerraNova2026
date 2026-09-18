<?php
declare(strict_types=1);

namespace App\Command;

use App\Application\Sales\Command\RunSalesAutomationCommand;
use Kernel\Application\Bus\CommandBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:sales:automation:scan',
    description: 'Dispatch the tenant-safe Sales automation scan through the async Messenger transport.',
)]
final class SalesAutomationScanCommand extends Command
{
    public function __construct(private readonly CommandBusInterface $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Restrict the scan to one organization.')
            ->addOption('no-activity-hours', null, InputOption::VALUE_REQUIRED, 'No-activity threshold in hours.', '48')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum signals inspected per detector and tenant.', '200')
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'Optional operational correlation token.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = trim((string) ($input->getOption('organization') ?? ''));
        $hours = max(1, (int) $input->getOption('no-activity-hours'));
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        $runId = trim((string) ($input->getOption('run-id') ?? ''));

        $this->commands->dispatch(new RunSalesAutomationCommand(
            $organization !== '' ? $organization : null,
            $hours,
            $limit,
            $runId !== '' ? $runId : null,
        ));

        $output->writeln(json_encode([
            'queued' => true,
            'organization_id' => $organization !== '' ? $organization : null,
            'no_activity_hours' => $hours,
            'limit' => $limit,
            'run_id' => $runId !== '' ? $runId : null,
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
