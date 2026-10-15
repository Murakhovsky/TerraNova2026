<?php
declare(strict_types=1);

namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\CrossVenueSubscriptionPlanner;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:subscriptions:plan',
    description:'Preview or create bounded BBO subscriptions for active canonical instruments.')]
final class CapitalMarketsSubscriptionPlanCommand extends Command
{
    public function __construct(
        private readonly CrossVenueSubscriptionPlanner $planner,
        private readonly CapitalMarketsAccessControlInterface $access,
    ) { parent::__construct(); }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization ID')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized actor ID')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Existing market-data source ID')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum BBO candidate count (1..250)','250')
            ->addOption('apply',null,InputOption::VALUE_NONE,'Create subscriptions; default is dry-run.');
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
        if (!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::MarketDataManage->value)
            && !$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)) {
            $output->writeln('FORBIDDEN');
            return Command::FAILURE;
        }
        try {
            $result=$input->getOption('apply')
                ?$this->planner->apply($org,$actor,$source,$limit)
                :['plan'=>$this->planner->plan($org,$source,$limit),'created'=>0,
                    'transport_state'=>'DRY_RUN'];
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        } catch (Throwable $e) {
            $output->writeln(json_encode(['status'=>'FAILED','reason'=>$e->getMessage()],
                JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
