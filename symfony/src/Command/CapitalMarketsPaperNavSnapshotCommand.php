<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Service\PaperNavSnapshotService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name:'cos:capital-markets:paper:nav:snapshot',
    description:'Write a simulation-only NAV observation from the canonical paper portfolio and fresh market marks.',
)]
final class CapitalMarketsPaperNavSnapshotCommand extends Command
{
    public function __construct(private readonly PaperNavSnapshotService $service) {parent::__construct();}

    protected function configure():void
    {
        $this->addOption('organization',null,InputOption::VALUE_REQUIRED,'Existing tenant organization ID')
            ->addOption('portfolio',null,InputOption::VALUE_REQUIRED,'Paper portfolio ID','paper-master');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getOption('organization'));
        $portfolio=trim((string)$input->getOption('portfolio'));
        if ($org==='' || $portfolio==='') {
            $output->writeln('{"status":"UNAVAILABLE","reason":"TENANT_OR_PORTFOLIO_REQUIRED"}');
            return Command::INVALID;
        }
        $result=$this->service->snapshot($org,$portfolio);
        $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
        return ($result['status']??'')==='SIMULATED'?Command::SUCCESS:Command::FAILURE;
    }
}
