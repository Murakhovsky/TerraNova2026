<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringContinueService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:continue', description: 'Continue a persisted COS engineering workflow from its current state.')]
final class EngineeringContinueCommand extends Command
{
    public function __construct(
        private readonly EngineeringContinueService $engineering,
        private readonly string $organizationId,
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
        $result = $this->engineering->continueFeature(
            $featureId,
            $this->organizationId,
            'engineering:continue:'.$featureId.':'.EngineeringId::generate(),
        );

        $output->writeln('Feature: '.$result->featureId);
        $output->writeln('Workflow: '.$result->workflowId);
        $output->writeln('State: '.$result->state);
        $output->writeln('Next: '.$result->next->type->value);
        if ($result->next->agent !== null) $output->writeln('Agent: '.$result->next->agent->value);
        if ($result->next->reason !== '') $output->writeln('Reason: '.$result->next->reason);
        return Command::SUCCESS;
    }
}
