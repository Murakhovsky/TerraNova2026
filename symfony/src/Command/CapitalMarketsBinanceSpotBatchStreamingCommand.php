<?php
declare(strict_types=1);

namespace App\Command;

use DateTimeImmutable;
use DateTimeZone;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\StreamingInstrumentTrustInvalidatorInterface;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureGate;
use Domains\CapitalMarkets\Application\Service\StreamingSessionCoordinator;
use Domains\CapitalMarkets\Application\Service\StreamingSubscriptionPlanner;
use Domains\CapitalMarkets\Application\Service\MultiSymbolStreamingCore;
use Domains\CapitalMarkets\Application\Service\StreamingWorkerRunner;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BinanceSpotMultiBboProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\NativeWebSocketTransport;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Explicit opt-in bounded Binance Spot bookTicker batch. One source lease,
 * one socket, per-instrument revocation, no live trading or opportunity gating.
 */
#[AsCommand(name:'cos:capital-markets:market-data:stream:binance-spot-batch',
    description:'Run a source-fenced 2..100 Binance Spot BBO streaming batch canary.')]
final class CapitalMarketsBinanceSpotBatchStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketSubscriptionRepositoryInterface $subscriptions,
        private readonly MarketInstrumentResolverInterface $resolver,
        private readonly BinanceSpotPayloadParser $parser,
        private readonly MultiSymbolStreamingCore $multiSymbolCore,
        private readonly StreamingInstrumentTrustInvalidatorInterface $instrumentTrust,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
    ){parent::__construct();}

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Configured Binance Spot source ID')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'Duration, 0 for SIGTERM-controlled run','60');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $org=trim((string)$input->getArgument('organization'));
        $sourceName=trim((string)$input->getArgument('source'));
        $seconds=filter_var($input->getOption('seconds'),FILTER_VALIDATE_INT);
        if($org===''||$sourceName===''||$seconds===false||$seconds<0||$seconds>86400){
            $output->writeln('INVALID_ARGUMENT');return Command::INVALID;
        }
        if(getenv('COS_CM_STREAMING_BINANCE_SPOT_BATCH_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBinance,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)){
            $output->writeln('STREAMING_DISABLED');return Command::FAILURE;
        }
        if($seconds===0&&!function_exists('pcntl_async_signals')){
            $output->writeln('UNBOUNDED_RUN_REQUIRES_PCNTL');return Command::FAILURE;
        }
        try{
            $sourceId=MarketSourceId::fromString($sourceName);
            $source=$this->sources->get($org,$sourceId);
            if($source===null||!$source->enabled
                ||$source->adapterType!==BinanceSpotMarketDataAdapter::ADAPTER_TYPE
                ||$source->venueId===null){
                throw new \DomainException('Enabled Binance Spot trading source with venue mapping required.');
            }
            // Unified preflight: exact mapping, per-source capacity and one fenced lease.
            $streamPlan=$this->multiSymbolCore->plan($org,[$sourceName]);
            if(($streamPlan['totals']['SOURCES']??0)!==1){
                throw new \DomainException('Multi-symbol streaming capacity admission rejected.');
            }
            $active=$this->subscriptions->forSource($org,$sourceId,true);
            if(count($active)<2||count($active)>100){
                throw new \DomainException('Binance multi BBO worker requires 2..100 active subscriptions.');
            }
            $keyToSymbol=[];
            $instrumentIdsBySymbol=[];
            $seen=[];
            foreach($active as $sub){
                if($sub->dataType!==MarketEventType::Bbo
                    ||$sub->venueId===null||!$sub->venueId->equals($source->venueId)){
                    throw new \DomainException('Binance batch requires only same-venue Spot BBO subscriptions.');
                }
                $target=$this->resolver->target($org,$source,$sub->instrumentId);
                if($target===null||$target->venueInstrument===null
                    ||!$target->venueInstrument->venueId->equals($source->venueId)){
                    throw new \DomainException('Active Binance subscription is missing a venue instrument mapping.');
                }
                $symbol=strtoupper($target->externalSymbol);
                if(preg_match('/^[A-Z0-9]{3,40}$/D',$symbol)!==1||isset($seen[$symbol])){
                    throw new \DomainException('Unmapped or repeated Binance batch symbol.');
                }
                $key=StreamingSubscriptionPlanner::key($sub);
                $keyToSymbol[$key]=$symbol;
                $instrumentIdsBySymbol[$symbol]=$sub->instrumentId->value();
                $seen[$symbol]=true;
            }

            $protocol=new BinanceSpotMultiBboProtocol(
                $sourceId,$source->venueId,$keyToSymbol,$this->parser,
            );
            $session=new StreamingSessionCoordinator(
                new StreamingRecoveryGate(),new StreamingSubscriptionReconciler(),
                new StreamingHeartbeatWatchdog(180000,120000),
            );
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $shard='binance-spot-multi-bbo';
            $workerId='binance-batch-'.bin2hex(random_bytes(12));
            $leaseToken=0;
            $accepted=0;
            $lastPing=$clock();
            $transport=null;
            $transport=new NativeWebSocketTransport(
                'wss://data-stream.binance.vision/ws',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $epoch)use(
                    $protocol,$session,$clock,$org,$sourceName,$shard,$workerId,
                    $instrumentIdsBySymbol,&$leaseToken,&$accepted,&$transport,
                ):void{
                    $event=$protocol->decode(
                        $payload,new DateTimeImmutable('now',new DateTimeZone('UTC')),
                    );
                    if($event['kind']==='ack'){
                        foreach($event['keys'] as $key)$session->subscribed($epoch,$key);
                        return;
                    }
                    if($event['kind']==='unsubscribed'){
                        $transport->sendText($protocol->resubscribeFrame($event['symbol']),$epoch);
                        return;
                    }
                    if($event['kind']==='resubscribed'||$event['kind']==='quarantined')return;
                    if($event['kind']==='heartbeat'){
                        $session->message($epoch,$clock());
                        return;
                    }
                    if(in_array($event['kind'],['invalid_quote','reset'],true)){
                        $symbol=$event['symbol'];
                        $instrumentId=$instrumentIdsBySymbol[$symbol]??null;
                        if(!is_string($instrumentId)){
                            throw new \DomainException('Binance multi-BBO recovery mapping unavailable.');
                        }
                        $this->instrumentTrust->invalidateInstrument(
                            $org,$sourceName,$shard,$workerId,$leaseToken,
                            $instrumentId,$event['reason'],
                        );
                        $transport->sendText($protocol->unsubscribeFrame($symbol),$epoch);
                        return;
                    }
                    $session->message($epoch,$clock());
                    if($session->status()==='SYNCING'){
                        $session->verifiedSnapshot($epoch,null,true);
                        $session->verifiedRecovery($epoch,true,true);
                    }elseif($session->trusted()){
                        $session->verifiedDelta($epoch,null,true,true);
                    }else{
                        throw new \DomainException('Binance batch stream not source-trusted.');
                    }
                    $stored=$this->ingestion->ingest(
                        $org,$sourceName,$shard,$workerId,$leaseToken,$event['raw'],
                    );
                    if(!in_array($stored->status,['ACCEPTED','DUPLICATE'],true)){
                        throw new \DomainException('Binance batch fenced ingestion rejected quote.');
                    }
                    if($stored->status==='ACCEPTED')$accepted++;
                },
                2097152,
                function(string $kind,int $epoch)use($session,$clock):void{
                    if($kind==='PONG')$session->pong($epoch,$clock());
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
                $org,$sourceName,$shard,$workerId,array_keys($keyToSymbol),
                $session,$transport,$clock,
                static function()use(&$stop,$clock,$seconds,$started):bool{
                    return $stop||($seconds>0&&$clock()-$started>=$seconds*1000);
                },
                function(int $epoch)use(
                    $session,$clock,$transport,$protocol,&$lastPing,$org,$sourceName,
                    $shard,$workerId,&$leaseToken,$instrumentIdsBySymbol,
                ):void{
                    $now=$clock();
                    if($now-$lastPing>=25000){
                        $transport->sendPing($epoch);
                        $lastPing=$now;
                    }
                    $session->tick($epoch,$now,true);
                    if($session->status()==='UNTRUSTED')return;
                    foreach($protocol->staleSymbols(
                        new DateTimeImmutable('now',new DateTimeZone('UTC')),120,
                    ) as $symbol){
                        $protocol->quarantineSymbol($symbol);
                        $id=$instrumentIdsBySymbol[$symbol]??null;
                        if(!is_string($id)){
                            throw new \DomainException('Stale Binance symbol has no instrument mapping.');
                        }
                        $this->instrumentTrust->invalidateInstrument(
                            $org,$sourceName,$shard,$workerId,$leaseToken,
                            $id,'BINANCE_SYMBOL_STALE',
                        );
                    }
                },
                30,5000,200,
                static function(int $token)use(&$leaseToken):void{$leaseToken=$token;},
                $source->reconnectPolicy,
            );
            $output->writeln(json_encode([
                'status'=>$result,'source'=>$sourceName,
                'symbols'=>$protocol->symbols(),'accepted'=>$accepted,
            ],JSON_THROW_ON_ERROR));
            return $result==='STOPPED'&&$accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            // Do not expose provider payloads, credentials or private state.
            $output->writeln(json_encode([
                'status'=>'FAILED_PRECHECK','reason'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
