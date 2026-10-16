<?php
declare(strict_types=1);
namespace App\Command;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\CapitalMarkets\Application\Feature\{CapitalMarketsFeatureFlag,CapitalMarketsFeatureGate};
use Domains\CapitalMarkets\Application\Service\{
    BybitDerivativesStreamScope,StreamingSessionCoordinator,StreamingWorkerRunner
};
use Domains\CapitalMarkets\Domain\Instrument\ContractType;
use Domains\CapitalMarkets\Domain\MarketData\{
    StreamingHeartbeatWatchdog,StreamingRecoveryGate,StreamingSubscriptionReconciler
};
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\{
    BybitLinearDerivativesProtocol,NativeWebSocketTransport,
    StreamingFencedIngestionGateway,MysqlDerivativeLifecycleRegistry,MysqlDerivativeStreamTrust
};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument,InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Provider ticker stream for independently mapped funding, mark, index, OI.
 * Distinct Bybit source per ticker worker; no shared-source parallel shards.
 * Research-only. No orders, funding settlement, or automatic lifecycle edits.
 */
#[AsCommand(name:'cos:capital-markets:market-data:stream:bybit-derivatives',
    description:'Opt-in canonical, source-fenced linear perpetual ticker derivatives stream.')]
final class CapitalMarketsBybitDerivativesStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly BybitDerivativesStreamScope $scope,
        private readonly BybitPerpetualMarketDataAdapter $provider,
        private readonly MysqlDerivativeLifecycleRegistry $lifecycle,
        private readonly MysqlDerivativeStreamTrust $trust,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Tenant organization')
            ->addArgument('source',InputArgument::REQUIRED,'Dedicated canonical Bybit derivatives source')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'0 for supervised indefinite worker','60')
            ->addOption('dry-run',null,InputOption::VALUE_NONE,'Read-only topology and entitlements check');
    }

    protected function execute(InputInterface $in,OutputInterface $out):int
    {
        try{
            $org=trim((string)$in->getArgument('organization'));
            $sourceName=trim((string)$in->getArgument('source'));
            $seconds=filter_var($in->getOption('seconds'),FILTER_VALIDATE_INT);
            if($org===''||strlen($org)>190||$sourceName===''||strlen($sourceName)>190
                ||$seconds===false||$seconds<0||$seconds>86400){
                throw new DomainException('Invalid derivatives stream tenant/source/duration.');
            }
            $scoped=$this->scope->resolve($org,$sourceName);
            $source=$scoped['source'];
            $profile=$this->provider->getPerpetualProfile($org,$source,$scoped['target']);
            if($profile->contractType!==ContractType::Linear
                ||!$profile->fundingSupported||$profile->fundingIntervalSeconds===null
                ||($profile->venueMetadata['status']??null)!=='Trading'){
                throw new DomainException('Bybit perpetual contract not currently tradable.');
            }
            $this->lifecycle->assertTrading($org,$sourceName,$scoped['instrument_id']);
            if($in->getOption('dry-run')){
                $out->writeln(json_encode(['status'=>'VALIDATED_NOT_RUNNING',
                    'source'=>$sourceName,'symbol'=>$scoped['symbol'],
                    'capabilities'=>4,'contract_family'=>'LINEAR_PERPETUAL',
                    'source_fenced'=>true,'trading_enabled'=>false],JSON_THROW_ON_ERROR));
                return Command::SUCCESS;
            }
            if(getenv('COS_CM_STREAMING_BYBIT_DERIVATIVES_ENABLED')!=='1'
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBybit,$org)
                ||!$this->flags->enabled(CapitalMarketsFeatureFlag::CryptoSpotPerpetual,$org)){
                throw new DomainException('Derivatives streaming is disabled by default.');
            }
            if($seconds===0&&!function_exists('pcntl_async_signals')){
                throw new DomainException('Supervised indefinite run requires PCNTL.');
            }
            $protocol=new BybitLinearDerivativesProtocol(
                $source->id,$source->venueId,$scoped['symbol'],$scoped['keys'],
                $profile->fundingIntervalSeconds,
                isset($profile->venueMetadata['upper_funding_rate'])
                    ?(string)$profile->venueMetadata['upper_funding_rate']:null,
                isset($profile->venueMetadata['lower_funding_rate'])
                    ?(string)$profile->venueMetadata['lower_funding_rate']:null,
            );
            $session=new StreamingSessionCoordinator(new StreamingRecoveryGate(),
                new StreamingSubscriptionReconciler(),new StreamingHeartbeatWatchdog(90000,90000));
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $shard='bybit-linear-derivatives';
            $worker='cm-derivatives-'.bin2hex(random_bytes(12));
            $token=0;$accepted=0;$lastPing=$clock();$lastSweep=$clock();
            $transport=null;
            $transport=new NativeWebSocketTransport(
                'wss://stream.bybit.com/v5/public/linear',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $epoch)use(
                    $protocol,$session,$clock,$org,$sourceName,$shard,$worker,&$token,
                    &$accepted,$scoped
                ):void{
                    $event=$protocol->decode($payload,new DateTimeImmutable('now',new DateTimeZone('UTC')));
                    if($event['kind']==='ack'){
                        foreach($protocol->keys() as $key)$session->subscribed($epoch,$key);
                        return;
                    }
                    if($event['kind']==='pong'){$session->pong($epoch,$clock());return;}
                    if($event['kind']==='heartbeat'){$session->message($epoch,$clock());return;}
                    $this->lifecycle->assertTrading($org,$sourceName,$scoped['instrument_id']);
                    $session->message($epoch,$clock());
                    if($event['kind']==='snapshot' && $session->status()==='SYNCING'){
                        $session->verifiedSnapshot($epoch,null,true);
                        $session->verifiedRecovery($epoch,true,true);
                    }elseif($session->trusted()){
                        $session->verifiedDelta($epoch,null,true,true);
                    }else{
                        throw new DomainException('Derivative ticker not synchronized.');
                    }
                    foreach($event['events'] as $raw){
                        $written=$this->ingestion->ingest(
                            $org,$sourceName,$shard,$worker,$token,$raw,
                        );
                        if(!in_array($written->status,['ACCEPTED','DUPLICATE'],true)){
                            throw new DomainException('Derivative canonical ingestion rejected.');
                        }
                        if($written->status==='ACCEPTED'){
                            $this->trust->record($org,$sourceName,$shard,$worker,$token,$epoch,$written);
                            $accepted++;
                        }
                    }
                },
                2097152,
                function(string $kind,int $epoch)use($session,$clock):void{
                    if($kind==='PONG'||$kind==='PING')$session->pong($epoch,$clock());
                },
            );
            $stop=false;
            if(function_exists('pcntl_async_signals')){
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM,static function()use(&$stop):void{$stop=true;});
                pcntl_signal(SIGINT,static function()use(&$stop):void{$stop=true;});
            }
            $started=$clock();
            $result=$this->runner->run(
                $org,$sourceName,$shard,$worker,$protocol->keys(),
                $session,$transport,$clock,
                static function()use(&$stop,$seconds,$started,$clock):bool{
                    return $stop||($seconds>0 && $clock()-$started >=$seconds*1000);
                },
                function(int $epoch)use($session,$clock,$transport,&$lastPing,&$lastSweep,
                    $org,$sourceName,$scoped):void{
                    $now=$clock();
                    $this->lifecycle->assertTrading($org,$sourceName,$scoped['instrument_id']);
                    if($now-$lastPing>=20000){
                        $transport->sendText('{"op":"ping"}',$epoch);
                        $lastPing=$now;
                    }
                    $session->tick($epoch,$now,true);
                    if($now-$lastSweep>=30000){
                        $this->trust->sweep($org,$sourceName,120);
                        $lastSweep=$now;
                    }
                },
                30,5000,200,static function(int $fence)use(&$token):void{$token=$fence;},
                $source->reconnectPolicy,
            );
            $out->writeln(json_encode(['status'=>$result,'accepted_events'=>$accepted,
                'source'=>$sourceName,'symbol'=>$scoped['symbol'],
                'trading_enabled'=>false],JSON_THROW_ON_ERROR));
            return $result==='STOPPED'&&$accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $out->writeln(json_encode(['status'=>'FAILED_PRECHECK',
                'reason'=>get_class($error)],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
