<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringIssueIntakeService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:issue', description: 'Import a GitHub Issue into COS Engineering and optionally start the autonomous workflow.')]
final class EngineeringIssueCommand extends Command
{
    public function __construct(
        private readonly EngineeringIssueIntakeService $engineering,
        private readonly string $organizationId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('issue', InputArgument::REQUIRED, 'GitHub issue number.')
            ->addOption('start', null, InputOption::VALUE_NONE, 'Start the autonomous workflow immediately.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issue = (int) $input->getArgument('issue');
        if ($issue <= 0) return Command::INVALID;

        $result = $this->engineering->import(
            issueNumber: $issue,
            start: (bool) $input->getOption('start'),
            organizationId: $this->organizationId,
            correlationId: 'engineering:issue:'.$issue.':'.EngineeringId::generate(),
        );

        $output->writeln('Feature: '.$result['feature_id']);
        $output->writeln('Issue: #'.$result['issue']);
        $output->writeln('State: '.$result['state']);
        if (isset($result['workflow_id'])) $output->writeln('Workflow: '.$result['workflow_id']);
        return Command::SUCCESS;
    }
}
