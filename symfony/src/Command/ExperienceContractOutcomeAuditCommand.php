<?php
declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Governance\PageContractOutcomeAuditService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'cos:experience:contracts:audit', description: 'Report P0/P1 Page Contract outcome and state gaps.')]
final class ExperienceContractOutcomeAuditCommand extends Command
{
    public function __construct(private readonly PageContractOutcomeAuditService $audit) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE)
            ->addOption('strict', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->audit->report();
        $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $input->getOption('strict') && !$report['structurally_complete'] ? Command::FAILURE : Command::SUCCESS;
    }
}
