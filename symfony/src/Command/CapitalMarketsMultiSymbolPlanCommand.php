<?php
declare(strict_types=1);
namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Application\Service\MultiSymbolStreamingCore;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Source-bounded BBO admission: no sockets, credentials or side effects. */
#[AsCommand(name:'cos:capital-markets:market-data:stream:multi-plan',
    description:'Preflight BBO batching, source capacity, mappings and reconnect budgets.')]
final class CapitalMarketsMultiSymbolPlanCommand extends Command
{
    public function __construct(
        private readonly MultiSymbolStreamingCore $planner,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized Market Data user')
            ->addOption('sources',null,InputOption::VALUE_REQUIRED,'Comma-separated source IDs');
    }
    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        try{
            $org=trim((string)$input->getArgument('organization'));
            $actor=filter_var($input->getArgument('actor'),FILTER_VALIDATE_INT);
            if($org===''||strlen($org)>190||$actor===false||$actor<1){
                throw new \DomainException('Valid tenant and operator required.');
            }
            if(!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::MarketDataManage->value)
                &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new \DomainException('Market Data management capability required.');
            }
            $raw=(string)$input->getOption('sources');
            if($raw==='')throw new \DomainException('Explicit --sources required.');
            $result=$this->planner->plan($org,explode(',',$raw));
            $output->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $output->writeln(json_encode(['status'=>'REJECTED','reason'=>get_class($error)],
                JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
