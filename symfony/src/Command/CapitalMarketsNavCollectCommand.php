<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\PortfolioNavEvidenceCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scheduler-safe NAV source preflight. It never writes an unverifiable
 * financial snapshot; external ledgers must be integrated first.
 */
#[AsCommand(name: 'cos:capital-markets:nav:collect', description: 'Inspect canonical NAV source completeness; fail closed if reconciliation is missing.')]
final class CapitalMarketsNavCollectCommand extends Command
{
    public function __construct(private readonly PortfolioNavEvidenceCollector $collector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization', null, InputOption::VALUE_REQUIRED, 'Organization ID; mandatory')
            ->addOption('portfolio', null, InputOption::VALUE_REQUIRED, 'Portfolio ID', 'paper-master');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = trim((string)$input->getOption('organization'));
        $portfolio = trim((string)$input->getOption('portfolio'));
        if ($organization === '' || $portfolio === '') {
            $output->writeln(json_encode(['status'=>'ERROR','reason'=>'TENANT_AND_PORTFOLIO_REQUIRED'], JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }
        $result = $this->collector->inspect($organization, $portfolio);
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return $result['status'] === 'READY' ? Command::SUCCESS : Command::FAILURE;
    }
}
