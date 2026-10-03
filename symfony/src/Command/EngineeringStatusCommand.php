<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringStatusService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:status', description: 'Show persistent COS engineering feature status.')]
final class EngineeringStatusCommand extends Command
{
    public function __construct(private readonly EngineeringStatusService $engineering)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('feature', InputArgument::REQUIRED, 'Engineering feature UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $featureId = EngineeringId::assert(trim((string) $input->getArgument('feature')));
        $status = $this->engineering->status($featureId);
        $feature = $status['feature'];
        $workflow = $status['workflow'];

        $output->writeln('Feature: '.$feature['id']);
        $output->writeln('Title: '.$feature['title']);
        $output->writeln('Status: '.$feature['status']);
        $output->writeln('Priority: '.$feature['priority']);
        $output->writeln('Workflow: '.($workflow['id'] ?? 'n/a'));
        $output->writeln('State: '.($workflow['state'] ?? $feature['status']));
        $output->writeln('Tasks: '.count($status['tasks']));
        $output->writeln('Agent runs: '.count($status['agent_runs']));
        $output->writeln('Artifacts: '.count($status['artifacts']));
        $output->writeln('Open human decisions: '.count($status['open_human_decisions']));

        foreach ($status['agent_runs'] as $run) {
            $output->writeln(sprintf('  Agent %s [%s] %s', $run['role'], $run['status'], $run['id']));
        }
        foreach ($status['open_human_decisions'] as $decision) {
            $output->writeln(sprintf('  Decision %s: %s', $decision['id'], $decision['question']));
        }

        return Command::SUCCESS;
    }
}
