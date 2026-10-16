<?php
declare(strict_types=1);
namespace App\Command;

use Domains\CapitalMarkets\Application\Contract\CapitalMarketsAccessControlInterface;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlPerInstrumentStreamTrust;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name:'cos:capital-markets:market-data:stream:instrument-trust',
    description:'Inspect per-instrument trust or expire stale symbol states without degrading sibling feeds.')]
final class CapitalMarketsInstrumentStreamTrustCommand extends Command
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly MysqlPerInstrumentStreamTrust $trust,
        private readonly CapitalMarketsAccessControlInterface $access,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('operation',InputArgument::REQUIRED,'status|sweep')
            ->addArgument('organization',InputArgument::REQUIRED,'Explicit tenant')
            ->addArgument('actor',InputArgument::REQUIRED,'Authorized Market Data operator')
            ->addOption('source',null,InputOption::VALUE_REQUIRED,'Required source ID')
            ->addOption('stale-seconds',null,InputOption::VALUE_REQUIRED,'Per-symbol maximum silence (30..3600)','120')
            ->addOption('limit',null,InputOption::VALUE_REQUIRED,'Maximum rows per pass (1..500)','250');
    }
    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $operation=(string)$in->getArgument('operation');
            $org=trim((string)$in->getArgument('organization'));
            $actor=filter_var($in->getArgument('actor'),FILTER_VALIDATE_INT);
            $source=trim((string)$in->getOption('source'));
            if($org===''||strlen($org)>190||$source===''||strlen($source)>190
                ||$actor===false||$actor<1||!in_array($operation,['status','sweep'],true)){
                throw new \DomainException('Explicit valid operation, tenant, actor and source required.');
            }
            $cap=$operation==='status'?CapitalMarketsCapability::MarketDataView->value:
                CapitalMarketsCapability::MarketDataManage->value;
            if(!$this->access->hasCapability($org,$actor,$cap)
                &&!$this->access->hasCapability($org,$actor,CapitalMarketsCapability::Manage->value)){
                throw new \DomainException('Missing Market Data operator capability.');
            }
            if($operation==='sweep'){
                if(getenv('COS_CM_INSTRUMENT_TRUST_SWEEPER_ENABLED')!=='1'){
                    throw new \DomainException('Instrument trust sweeper is disabled.');
                }
                $age=filter_var($in->getOption('stale-seconds'),FILTER_VALIDATE_INT);
                $limit=filter_var($in->getOption('limit'),FILTER_VALIDATE_INT);
                if($age===false||$limit===false||$age<30||$age>3600||$limit<1||$limit>500){
                    throw new \DomainException('Invalid bounded sweep policy.');
                }
                $result=$this->trust->sweepStale($org,$source,$age,$limit);
            }else{
                $stmt=$this->pdo->prepare(
                    "SELECT instrument_id,channel,status,last_sequence,reason,recovery_required,
                            last_event_at,last_quality_score
                     FROM tn_capital_market_instrument_stream_trust
                     WHERE organization_id=:org AND source_id=:source
                     ORDER BY instrument_id,channel LIMIT 500");
                $stmt->execute(['org'=>$org,'source'=>$source]);
                $result=['source'=>$source,'rows'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
            }
            $out->writeln(json_encode(['status'=>'OK','result'=>$result],
                JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }catch(Throwable $e){
            $out->writeln(json_encode(['status'=>'REJECTED','reason'=>get_class($e)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
