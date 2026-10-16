<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Governance\ProductUxGovernanceService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cos:experience:product-governance',
    description: 'Audit Product UX personas, journeys, terminology, debt and pre-acceptance contract quality.',
)]
final class ExperienceProductGovernanceCommand extends Command
{
    public function __construct(private readonly ProductUxGovernanceService $governance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output structured JSON.')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Fail on structural governance errors without impersonating human acceptance.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->governance->report();
        $data = $report->toArray();

        if ($input->getOption('json')) {
            $output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return $input->getOption('strict') && !$report->isStructurallyReady() ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('COS Experience Product UX Governance');
        $io->definitionList(
            ['Structural state' => $report->isStructurallyReady() ? 'READY' : 'BLOCKED'],
            ['Personas' => $report->personas],
            ['Journeys' => $report->journeys],
            ['Terminology terms' => $report->terminologyTerms],
            ['Generic Page Contracts' => count($report->genericContractCopy)],
            ['Open UX debt' => $report->openUxDebt],
            ['Open Design System gaps' => $report->openDesignSystemGaps],
            ['Priority distribution' => json_encode($report->priorityCounts, JSON_UNESCAPED_SLASHES)],
            ['Priority inflation' => $report->priorityInflated ? 'ATTENTION' : 'OK'],
        );

        if ($report->missingPersonaReferences !== []) {
            $io->error('Unknown page personas: '.implode(', ', $report->missingPersonaReferences));
        }
        if ($report->journeyUnknownPersonas !== []) {
            $io->error('Unknown journey personas: '.implode(', ', $report->journeyUnknownPersonas));
        }
        if ($report->journeyUnknownPages !== []) {
            $io->error('Unknown journey pages: '.implode(', ', $report->journeyUnknownPages));
        }
        if ($report->genericContractCopy !== []) {
            $io->warning(sprintf(
                '%d auto-inventoried Page Contracts still need page-specific Product UX copy before Experience V1 release.',
                count($report->genericContractCopy),
            ));
        }

        return $input->getOption('strict') && !$report->isStructurallyReady() ? Command::FAILURE : Command::SUCCESS;
    }
}
