<?php
declare(strict_types=1);
namespace App\Command;

use DomainException;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingFailoverAudit;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** No automatic production outage; SOAK-only double-armed source fencing. */
#[AsCommand(name:'cos:capital-markets:market-data:stream:failover',
    description:'Challenge and verify real source-worker lease fencing and recovery during supervised SOAK.')]
final class CapitalMarketsStreamingFailoverCommand extends Command
{
    public function __construct(
        private readonly MysqlStreamingFailoverAudit $audit,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('operation',InputArgument::REQUIRED,'inject|verify|status')
            ->addArgument('organization',InputArgument::REQUIRED,'Explicit tenant')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized operator')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Selected SOAK source')
            ->addOption('confirm',null,InputOption::VALUE_REQUIRED,'Explicit injection confirmation');
    }
    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $action=(string)$in->getArgument('operation');
            $org=trim((string)$in->getArgument('organization'));
            $source=trim((string)$in->getOption('source'));
            $actor=filter_var($in->getArgument('actor'),FILTER_VALIDATE_INT);
            if($org===''||strlen($org)>190||$source===''||strlen($source)>190
                ||$actor===false||$actor<1
                ||!in_array($action,['inject','verify','status'],true)){
                throw new DomainException('Explicit tenant/source/operator/action required.');
            }
            $cap=$action==='status'
                ?CapitalMarketsCapability::MarketDataView->value
                :CapitalMarketsCapability::MarketDataManage->value;
            if(!$this->access->hasCapability($org,$actor,$cap)
                &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new DomainException('Not authorized for source failover.');
            }
            if($action==='inject'){
                if(getenv('COS_CM_STREAM_FAILOVER_DRILL_ARMED')!=='1'
                    ||$in->getOption('confirm')!=='INJECT_FENCED_FAILOVER'){
                    throw new DomainException('Failover injection requires double explicit arming.');
                }
                $result=$this->audit->inject($org,$source);
            }elseif($action==='verify'){
                $result=$this->audit->verify($org,$source);
            }else{
                $result=['status'=>'READ_ONLY','source'=>$source,
                    'instructions'=>'Failover proof is checked by 24h production acceptance report.'];
            }
            $out->writeln(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable $error){
            $out->writeln(json_encode(['status'=>'REJECTED','type'=>get_class($error)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
