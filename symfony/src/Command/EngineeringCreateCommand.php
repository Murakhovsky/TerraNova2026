<?php
declare(strict_types=1);

namespace App\Command;

use App\Engineering\Application\DTO\EngineeringRequest;
use App\Engineering\Application\Service\EngineeringOrchestrator;
use App\Engineering\Domain\Workflow\EngineeringId;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:engineering:create', description: 'Create a persistent COS engineering feature request.')]
final class EngineeringCreateCommand extends Command
{
    public function __construct(private readonly EngineeringOrchestrator $engineering)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('description', InputArgument::REQUIRED, 'Engineering request description.')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'Optional feature title.')
            ->addOption('priority', null, InputOption::VALUE_REQUIRED, 'Priority P0-P3.', 'P2')
            ->addOption('source-reference', null, InputOption::VALUE_REQUIRED, 'Optional external source reference.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $priority = strtoupper(trim((string) $input->getOption('priority')));
        if (!in_array($priority, ['P0','P1','P2','P3'], true)) {
            $output->writeln('<error>Priority must be one of P0, P1, P2, P3.</error>');
            return Command::INVALID;
        }

        $request = new EngineeringRequest(
            requestId: EngineeringId::generate(),
            description: trim((string) $input->getArgument('description')),
            title: ($title = trim((string) $input->getOption('title'))) !== '' ? $title : null,
            sourceType: $input->getOption('source-reference') !== null ? 'external' : 'user',
            sourceReference: $input->getOption('source-reference') !== null ? trim((string) $input->getOption('source-reference')) : null,
            priority: $priority,
        );

        $featureId = $this->engineering->create($request, 'cli');
        $output->writeln($featureId);
        return Command::SUCCESS;
    }
}
