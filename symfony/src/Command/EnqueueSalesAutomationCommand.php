<?php
declare(strict_types=1);

namespace App\Command;

use App\Application\Sales\Command\RunSalesAutomationCommand;
use App\Application\System\Command\DrainSalesOutboxCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

#[AsCommand(
    name: 'cos:sales:automation:enqueue',
    description: 'Queue one Sales monitoring + Sales outbox automation cycle.',
)]
final class EnqueueSalesAutomationCommand extends Command
{
    public function __construct(private readonly MessageBusInterface $commandBus)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Limit monitoring to one organization')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Detector/outbox batch limit', '200')
            ->addOption('no-activity-hours', null, InputOption::VALUE_REQUIRED, 'No-activity threshold', '48');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = trim((string) $input->getOption('organization'));
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        $hours = max(1, (int) $input->getOption('no-activity-hours'));

        $this->commandBus->dispatch(
            new RunSalesAutomationCommand($organization !== '' ? $organization : null, $hours, $limit, 'manual'),
            [new TransportNamesStamp(['async'])],
        );
        $this->commandBus->dispatch(
            new DrainSalesOutboxCommand($limit, 'manual-sales-outbox'),
            [new TransportNamesStamp(['async'])],
        );

        $output->writeln('Sales automation cycle queued.');

        return Command::SUCCESS;
    }
}
