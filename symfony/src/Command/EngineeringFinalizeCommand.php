<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringFinalizeService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:finalize', description: 'Complete an Engineering workflow after a verified human GitHub merge.')]
final class EngineeringFinalizeCommand extends Command
{
    public function __construct(private readonly EngineeringFinalizeService $engineering)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('feature', InputArgument::REQUIRED, 'Engineering feature UUID.')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Human approver identifier.', 'cli-human');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $featureId = EngineeringId::assert(trim((string) $input->getArgument('feature')));
        $actor = trim((string) $input->getOption('actor'));
        if ($actor === '') return Command::INVALID;

        $result = $this->engineering->finalize($featureId, $actor);
        $output->writeln('Feature: '.$result['feature_id']);
        $output->writeln('Workflow: '.$result['workflow_id']);
        $output->writeln('State: '.$result['state']);
        $output->writeln('Merge revision: '.$result['pull_request']['merge_revision']);
        return Command::SUCCESS;
    }
}
