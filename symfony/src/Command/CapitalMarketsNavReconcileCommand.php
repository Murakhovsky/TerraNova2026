<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\PortfolioNavIndependentReconciliationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'cos:capital-markets:nav:reconcile',
    description: 'Second-person NAV approval from imported independent statements, accounting journal and custody marks.',
)]
final class CapitalMarketsNavReconcileCommand extends Command
{
    public function __construct(private readonly PortfolioNavIndependentReconciliationService $service)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('organization',null,InputOption::VALUE_REQUIRED,'Organization ID')
            ->addOption('portfolio',null,InputOption::VALUE_REQUIRED,'Portfolio ID','paper-master')
            ->addOption('reviewer',null,InputOption::VALUE_REQUIRED,'Actual authorized COS financial reviewer user ID')
            ->addOption('approve',null,InputOption::VALUE_REQUIRED,'Explicit approval: APPROVE_VERIFIED_INDEPENDENT_EVIDENCE');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getOption('organization'));
        $portfolio=trim((string)$input->getOption('portfolio'));
        $reviewer=filter_var($input->getOption('reviewer'),FILTER_VALIDATE_INT,[
            'options'=>['min_range'=>1],
        ]);
        if ($org==='' || $portfolio==='' || $reviewer===false) {
            $output->writeln(json_encode([
                'status'=>'BLOCKED','issues'=>['ORGANIZATION_PORTFOLIO_REVIEWER_REQUIRED'],
            ],JSON_THROW_ON_ERROR));
            return Command::INVALID;
        }
        try {
            $result=$this->service->certify(
                $org,$portfolio,(int)$reviewer,trim((string)$input->getOption('approve')),
            );
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return ($result['status']??'')==='COMPLETE' ? Command::SUCCESS : Command::FAILURE;
        } catch (Throwable) {
            // Never expose private financial statements, DB credentials or stack traces.
            $output->writeln(json_encode([
                'status'=>'BLOCKED','snapshot_written'=>false,
                'issues'=>['RECONCILIATION_SOURCE_OR_STORAGE_UNAVAILABLE'],
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
