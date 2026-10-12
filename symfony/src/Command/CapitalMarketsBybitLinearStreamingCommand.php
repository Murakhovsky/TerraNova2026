<?php
declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Service\StreamingSessionCoordinator;
use Domains\CapitalMarkets\Application\Service\StreamingSubscriptionPlanner;
use Domains\CapitalMarkets\Application\Service\StreamingWorkerRunner;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitOrderBookPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Bybit\BybitPerpetualMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BybitLinearTopBookProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\NativeWebSocketTransport;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** Explicitly gated Bybit Linear Perpetual level-one BBO pilot, one subscription. */
#[AsCommand(name:'cos:capital-markets:market-data:stream:bybit-linear',
    description:'Run the single-symbol Bybit Linear Perpetual top-of-book WebSocket pilot.')]
final class CapitalMarketsBybitLinearStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketSubscriptionRepositoryInterface $subscriptions,
        private readonly MarketInstrumentResolverInterface $resolver,
        private readonly BybitOrderBookPayloadParser $orderBooks,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Configured Bybit Linear Perpetual source ID')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'Duration, 0 for long-lived','60');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $sourceName=trim((string)$input->getArgument('source'));
        $seconds=filter_var($input->getOption('seconds'),FILTER_VALIDATE_INT);
        if($org===''||$sourceName===''||$seconds===false||$seconds<0||$seconds>86400){
            $output->writeln('INVALID_ARGUMENT');
            return Command::INVALID;
        }
        if(getenv('COS_CM_STREAMING_BYBIT_LINEAR_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBybit,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::CryptoSpotPerpetual,$org)){
            $output->writeln('STREAMING_DISABLED');
            return Command::FAILURE;
        }
        if($seconds===0&&!function_exists('pcntl_async_signals')){
            $output->writeln('UNBOUNDED_RUN_REQUIRES_PCNTL');
            return Command::FAILURE;
        }
        try{
            $sourceId=MarketSourceId::fromString($sourceName);
            $source=$this->sources->get($org,$sourceId);
            if($source===null||!$source->enabled
                ||$source->adapterType!==BybitPerpetualMarketDataAdapter::ADAPTER_TYPE
                ||$source->venueId===null){
                throw new \DomainException('Enabled Bybit Linear Perpetual source required.');
            }
            $subs=$this->subscriptions->forSource($org,$sourceId,true);
            if(count($subs)!==1||$subs[0]->dataType!==MarketEventType::Bbo){
                throw new \DomainException('Bybit pilot requires exactly one BBO subscription.');
            }
            $target=$this->resolver->target($org,$source,$subs[0]->instrumentId);
            if($target===null||$target->venueInstrument===null
                ||$target->instrument->family!==\Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily::Perpetual
                ||!$target->venueInstrument->venueId->equals($source->venueId)){
                throw new \DomainException('Bybit venue instrument mapping unavailable.');
            }
            $symbol=strtoupper($target->externalSymbol);
            $key=StreamingSubscriptionPlanner::key($subs[0]);
            $protocol=new BybitLinearTopBookProtocol($sourceId,$source->venueId,$key,$symbol,$this->orderBooks);
            $session=new StreamingSessionCoordinator(new StreamingRecoveryGate(),
                new StreamingSubscriptionReconciler(),new StreamingHeartbeatWatchdog(90000,90000));
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $workerId='bybit-linear-topbook-'.bin2hex(random_bytes(10));
            $shard='bybit-linear-single-bbo';
            $token=0;
            $accepted=0;
            $lastPing=$clock();
            $transport=new NativeWebSocketTransport(
                'wss://stream.bybit.com/v5/public/linear',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $generation)use(
                    $protocol,$session,$clock,$org,$sourceName,$shard,$workerId,&$token,&$accepted,
                ):void{
                    $result=$protocol->decode($payload,new DateTimeImmutable('now',new DateTimeZone('UTC')));
                    if($result['kind']==='ack'){
                        $session->subscribed($generation,$protocol->key());
                        return;
                    }
                    if($result['kind']==='pong'){
                        $session->pong($generation,$clock());
                        return;
                    }
                    if($result['kind']==='heartbeat'){
                        $session->message($generation,$clock());
                        return;
                    }
                    if($result['kind']==='reset'){
                        $session->disconnected($generation,'BYBIT_LINEAR_ORDERBOOK_RESET');
                        return;
                    }
                    $session->message($generation,$clock());
                    if($session->status()==='SYNCING'){
                        // Entire level-one quote; NOT a validated L2 delta.
                        $session->verifiedSnapshot($generation,$result['update_id'],true);
                        $session->verifiedRecovery($generation,true,true);
                    }elseif($session->trusted()){
                        $session->verifiedDelta($generation,$result['update_id'],true,true);
                    }else{
                        throw new \DomainException('Untrusted Bybit session cannot publish BBO.');
                    }
                    $written=$this->ingestion->ingest(
                        $org,$sourceName,$shard,$workerId,$token,$result['raw']
                    );
                    if(!in_array($written->status,['ACCEPTED','DUPLICATE'],true)){
                        throw new \DomainException('Bybit BBO ingestion rejected.');
                    }
                    $accepted++;
                },
                2097152,
                function(string $kind,int $generation)use($session,$clock):void{
                    if($kind==='PING'||$kind==='PONG')$session->pong($generation,$clock());
                },
            );
            $stop=false;
            if(function_exists('pcntl_async_signals')){
                pcntl_async_signals(true);
                pcntl_signal(SIGTERM,static function()use(&$stop):void{$stop=true;});
                pcntl_signal(SIGINT,static function()use(&$stop):void{$stop=true;});
            }
            $started=$clock();
            $status=$this->runner->run(
                $org,$sourceName,$shard,$workerId,[$key],$session,$transport,$clock,
                static function()use(&$stop,$clock,$seconds,$started):bool{
                    return $stop || ($seconds>0&&$clock()-$started>=$seconds*1000);
                },
                static function(int $generation)use($session,$clock,$transport,&$lastPing):void{
                    $now=$clock();
                    $session->tick($generation,$now,true);
                    if($session->status()!=='UNTRUSTED'&&$now-$lastPing>=20000){
                        $transport->sendText('{"op":"ping"}',$generation);
                        $lastPing=$now;
                    }
                },
                30,5000,200,static function(int $t)use(&$token):void{$token=$t;},
                $source->reconnectPolicy,
            );
            $output->writeln(json_encode([
                'status'=>$status,'accepted'=>$accepted,'source'=>$sourceName,'symbol'=>$symbol,
            ],JSON_THROW_ON_ERROR));
            return $status==='STOPPED'&&$accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED_PRECHECK','reason'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
