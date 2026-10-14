<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\BStocksReferenceBboPlanner;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:subscriptions:reference-plan',
    description:'Preview or create bounded Massive reference BBO subscriptions for ACTIVE canonical equity/ETF.')]
final class CapitalMarketsReferenceBboPlanCommand extends Command
{
    public function __construct(
        private readonly BStocksReferenceBboPlanner $planner,
        private readonly CapitalMarketsAccessControlInterface $access,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized service actor ID')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Configured Massive market-data source ID')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum reference tickers (1..250)','250')
            ->addOption('apply',null,InputOption::VALUE_NONE,'Create audited BBO subscriptions; otherwise dry-run.');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $actor=filter_var($input->getArgument('actor'),FILTER_VALIDATE_INT);
        $source=trim((string)$input->getOption('source'));
        $limit=filter_var($input->getOption('limit'),FILTER_VALIDATE_INT);
        if ($org==='' || strlen($org)>190 || $actor===false || $actor<1
            || $source==='' || $limit===false || $limit<1 || $limit>250) {
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        if (!($this->access->hasCapability($org,$actor,CapitalMarketsCapability::MarketDataManage->value)
                || $this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value))
            || !($this->access->hasCapability($org,$actor,CapitalMarketsCapability::InstrumentManage->value)
                || $this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value))) {
            $output->writeln('FORBIDDEN');
            return Command::FAILURE;
        }
        try {
            $result=$input->getOption('apply') ?
                $this->planner->apply($org,$actor,$source,$limit)
                :['status'=>'DRY_RUN','plan'=>$this->planner->plan($org,$source,$limit)];
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(json_encode(['status'=>'FAILED','error_type'=>get_class($e),
                'reason'=>$e->getMessage()],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
