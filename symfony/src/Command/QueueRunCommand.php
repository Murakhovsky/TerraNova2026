<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Queue\Service\QueueWorker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:queue:run', description: 'Process a bounded batch from the canonical COS job queue.')]
final class QueueRunCommand extends Command
{
    public function __construct(private readonly QueueWorker $worker)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker-id', null, InputOption::VALUE_REQUIRED)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum jobs to process.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = trim((string) ($input->getOption('worker-id') ?? ''));
        if ($workerId === '') {
            $workerId = (gethostname() ?: 'cos') . '-queue-' . getmypid();
        }
        $limit = max(1, min(1000, (int) $input->getOption('limit')));
        $processed = 0;
        while ($processed < $limit && $this->worker->runOne($workerId)) {
            $processed++;
        }
        $output->writeln(json_encode(['processed' => $processed], JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
