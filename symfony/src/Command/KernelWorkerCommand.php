<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Operations\Service\WorkerSupervisor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:kernel:worker',
    description: 'Run the canonical COS event-outbox and kernel job-queue worker.',
)]
final class KernelWorkerCommand extends Command
{
    public function __construct(private readonly WorkerSupervisor $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('runtime-seconds', null, InputOption::VALUE_REQUIRED, 'Maximum runtime before clean restart.', '3600')
            ->addOption('idle-milliseconds', null, InputOption::VALUE_REQUIRED, 'Idle sleep between empty polls.', '250');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = trim((string) ($input->getOption('worker-id') ?? ''));
        if ($workerId === '') {
            $workerId = (gethostname() ?: 'cos') . '-kernel-' . getmypid();
        }

        $result = $this->worker->run(
            $workerId,
            max(1, (int) $input->getOption('runtime-seconds')),
            max(25, min(5000, (int) $input->getOption('idle-milliseconds'))),
        );

        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
