<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Persistence\EngineeringFeatureStoreInterface;
use App\Engineering\Application\Persistence\EngineeringTaskStoreInterface;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:status', description: 'Show persistent COS engineering feature status.')]
final class EngineeringStatusCommand extends Command
{
    public function __construct(
        private readonly EngineeringFeatureStoreInterface $features,
        private readonly EngineeringTaskStoreInterface $tasks,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('feature', InputArgument::REQUIRED, 'Engineering feature UUID.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $featureId = EngineeringId::assert(trim((string) $input->getArgument('feature')));
        $feature = $this->features->view($featureId);

        $output->writeln('Feature: '.$feature['id']);
        $output->writeln('Title: '.$feature['title']);
        $output->writeln('Status: '.$feature['status']);
        $output->writeln('Priority: '.$feature['priority']);
        $output->writeln('Repository revision: '.($feature['repository_revision'] ?? 'n/a'));

        $tasks = $this->tasks->forFeature($featureId);
        $output->writeln('Tasks: '.count($tasks));
        foreach ($tasks as $task) {
            $output->writeln(sprintf('  %s [%s] → %s', $task['external_key'], $task['status'], $task['assigned_role']));
        }

        return Command::SUCCESS;
    }
}
