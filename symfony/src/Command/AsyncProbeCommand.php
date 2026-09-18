<?php
declare(strict_types=1);

namespace App\Command;

use App\Application\System\Command\SchedulerHeartbeatCommand;
use Kernel\Application\Bus\CommandBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:async:probe', description: 'Dispatch a harmless command through the Redis-backed async Messenger transport.')]
final class AsyncProbeCommand extends Command
{
    public function __construct(private readonly CommandBusInterface $commands)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('token', null, InputOption::VALUE_REQUIRED, 'Probe token', 'manual-probe');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $token = trim((string) $input->getOption('token')) ?: 'manual-probe';
        $this->commands->dispatch(new SchedulerHeartbeatCommand($token));
        $output->writeln($token);

        return Command::SUCCESS;
    }
}
