<?php
declare(strict_types=1);

namespace App\Command;

use Kernel\Agent\Contract\AgentRetentionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:agent:purge-inputs', description: 'Purge expired retained agent input snapshots.')]
final class AgentPurgeInputsCommand extends Command
{
    public function __construct(private readonly AgentRetentionInterface $retention)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(json_encode(
            ['purged' => $this->retention->purgeExpiredInputs()],
            JSON_THROW_ON_ERROR,
        ));
        return Command::SUCCESS;
    }
}
