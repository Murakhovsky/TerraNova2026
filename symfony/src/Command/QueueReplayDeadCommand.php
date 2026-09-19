<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Queue\Contract\JobQueueInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:queue:replay-dead', description: 'Return dead COS jobs to the canonical queue.')]
final class QueueReplayDeadCommand extends Command
{
    public function __construct(private readonly JobQueueInterface $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED)
            ->addOption('job-id', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationId = trim((string) ($input->getOption('organization') ?? ''));
        $jobId = trim((string) ($input->getOption('job-id') ?? ''));
        $count = $this->queue->replayDead(
            $organizationId !== '' ? $organizationId : null,
            $jobId !== '' ? $jobId : null,
        );
        $output->writeln(json_encode(['replayed' => $count], JSON_THROW_ON_ERROR));
        return Command::SUCCESS;
    }
}
