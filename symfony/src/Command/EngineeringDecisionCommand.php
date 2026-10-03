<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\Service\EngineeringHumanDecisionService;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:decision', description: 'Answer a blocking engineering human-decision request and resume the workflow.')]
final class EngineeringDecisionCommand extends Command
{
    public function __construct(
        private readonly EngineeringHumanDecisionService $decisions,
        private readonly string $organizationId,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('request', InputArgument::REQUIRED, 'Human decision request UUID.')
            ->addArgument('option', InputArgument::REQUIRED, 'Selected option/value.')
            ->addOption('comment', null, InputOption::VALUE_REQUIRED, 'Optional human comment.')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Decision actor.', 'cli-human');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $requestId = EngineeringId::assert(trim((string) $input->getArgument('request')));
        $option = trim((string) $input->getArgument('option'));
        $actor = trim((string) $input->getOption('actor'));
        if ($option === '' || $actor === '') {
            $output->writeln('<error>Decision option and actor are required.</error>');
            return Command::INVALID;
        }

        $result = $this->decisions->answerAndResume(
            requestId: $requestId,
            selectedOption: $option,
            comment: $input->getOption('comment') !== null ? (string) $input->getOption('comment') : null,
            decidedBy: $actor,
            organizationId: $this->organizationId,
            correlationId: 'engineering:decision:'.$requestId.':'.EngineeringId::generate(),
        );

        $output->writeln('Feature: '.$result->featureId);
        $output->writeln('Workflow: '.$result->workflowId);
        $output->writeln('Decision: '.$result->decisionId);
        $output->writeln('State: '.$result->state);
        $output->writeln('Next: '.$result->next->type->value);
        if ($result->next->agent !== null) $output->writeln('Agent: '.$result->next->agent->value);

        return Command::SUCCESS;
    }
}
