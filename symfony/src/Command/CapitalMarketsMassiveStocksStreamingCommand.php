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
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Massive\MassiveStocksReferenceAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Security\MarketSourceCredentialResolver;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MassiveStocksQuoteProtocol;
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
 * Single ticker Massive stocks NBBO. No API key ever passes via CLI option,
 * URL, exception message or output. Configure credential reference in Vault.
 */
#[AsCommand(
    name:'cos:capital-markets:market-data:stream:massive-stocks',
    description:'Run the feature-gated Massive US stock NBBO reference stream canary.',
)]
final class CapitalMarketsMassiveStocksStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketSubscriptionRepositoryInterface $subscriptions,
        private readonly MarketInstrumentResolverInterface $resolver,
        private readonly MarketSourceCredentialResolver $credentials,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Configured Massive stock reference source')
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
        if(getenv('COS_CM_STREAMING_MASSIVE_STOCKS_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataMassive,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::TokenizedEquity,$org)){
            $output->writeln('STREAMING_DISABLED');return Command::FAILURE;
        }
        if($seconds===0&&!function_exists('pcntl_async_signals')){
            $output->writeln('UNBOUNDED_RUN_REQUIRES_PCNTL');return Command::FAILURE;
        }
        try{
            $sourceId=MarketSourceId::fromString($sourceName);
            $source=$this->sources->get($org,$sourceId);
            if($source===null||!$source->enabled
                ||$source->adapterType!==MassiveStocksReferenceAdapter::ADAPTER_TYPE
                ||$source->venueId!==null
                ||!$source->hasRole(MarketSourceRole::ReferenceSource)
                ||$source->hasRole(MarketSourceRole::TradingSource)){
                throw new \DomainException('Massive stream requires enabled reference-only stock source.');
            }
            $mode=match($source->metadata['data_mode']??null){
                'LIVE'=>MarketDataMode::Live,
                'DELAYED'=>MarketDataMode::Delayed,
                default=>throw new \DomainException('Massive stream requires explicit data_mode.'),
            };
            $requiredProfile=$mode===MarketDataMode::Live
                ?'MASSIVE_STOCKS_WS_Q_LIVE':'MASSIVE_STOCKS_WS_Q_DELAYED';
            if($source->licenseProfile!==$requiredProfile){
                throw new \DomainException('Massive stock WS entitlement must be explicitly attested.');
            }
            $subscribed=$this->subscriptions->forSource($org,$sourceId,true);
            if(count($subscribed)!==1||$subscribed[0]->dataType!==MarketEventType::Bbo){
                throw new \DomainException('Massive stock WS canary requires exactly one active BBO subscription.');
            }
            $target=$this->resolver->target($org,$source,$subscribed[0]->instrumentId);
            if($target===null||$target->venueInstrument!==null){
                throw new \DomainException('Missing Massive underlying equity reference instrument.');
            }
            $symbol=strtoupper($target->externalSymbol);
            if(preg_match('/^[A-Z][A-Z0-9.-]{0,31}$/D',$symbol)!==1){
                throw new \DomainException('Invalid Massive stock ticker.');
            }
            // Resolve only via the existing tenant-scoped encrypted credential vault.
            $apiKey=$this->credentials->apiKey($org,$source,'capital_markets.market_data.read');
            $key=StreamingSubscriptionPlanner::key($subscribed[0]);
            $protocol=new MassiveStocksQuoteProtocol($sourceId,$key,$symbol,$mode,$apiKey);
            $session=new StreamingSessionCoordinator(
                new StreamingRecoveryGate(),new StreamingSubscriptionReconciler(),
                new StreamingHeartbeatWatchdog(90000,120000),
            );
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $shard='massive-stocks-single-bbo';
            $workerId='massive-stocks-'.bin2hex(random_bytes(12));
            $token=0;
            $accepted=0;
            $transport=null;
            $lastPing=$clock();
            $transport=new NativeWebSocketTransport(
                $mode===MarketDataMode::Live
                    ?'wss://socket.massive.com/stocks'
                    :'wss://delayed.massive.com/stocks',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $epoch) use(
                    $protocol,$session,$clock,$org,$sourceName,$shard,$workerId,
                    &$transport,&$token,&$accepted,
                ):void{
                    $received=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                    foreach($protocol->decodeBatch($payload,$received) as $event){
                        if($event['kind']==='connected')continue;
                        if($event['kind']==='auth'){
                            $transport->sendText($protocol->subscriptionFrame(),$epoch);
                            continue;
                        }
                        if($event['kind']==='ack'){
                            $session->subscribed($epoch,$protocol->key());
                            continue;
                        }
                        if($event['kind']==='heartbeat'){
                            $session->message($epoch,$clock());
                            continue;
                        }
                        if($event['kind']==='one_sided'||$event['kind']==='reset'){
                            $session->disconnected($epoch,'MASSIVE_MARKET_UNVERIFIED');
                            // Never process subsequent quote items in this
                            // batch after invalidating the current generation.
                            break;
                        }
                        $session->message($epoch,$clock());
                        if($session->status()==='SYNCING'){
                            // Each Q event is a self-contained NBBO observation,
                            // not a stock order-book snapshot or contiguous delta.
                            $session->verifiedSnapshot($epoch,$event['sequence'],true);
                            $session->verifiedRecovery($epoch,true,true);
                        }elseif($session->trusted()){
                            $session->verifiedDelta($epoch,$event['sequence'],true,true);
                        }else{
                            throw new \DomainException('Unverified Massive quote cannot publish.');
                        }
                        $stored=$this->ingestion->ingest(
                            $org,$sourceName,$shard,$workerId,$token,$event['raw'],
                        );
                        if(!in_array($stored->status,['ACCEPTED','DUPLICATE'],true)){
                            throw new \DomainException('Massive reference NBBO ingestion rejected.');
                        }
                        if($stored->status==='ACCEPTED')$accepted++;
                    }
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
                $org,$sourceName,$shard,$workerId,[$key],$session,$transport,$clock,
                static function()use(&$stop,$clock,$seconds,$started):bool{
                    return $stop||($seconds>0&&$clock()-$started>=$seconds*1000);
                },
                static function(int $epoch)use($session,$clock,$transport,&$lastPing):void{
                    $now=$clock();
                    if($now-$lastPing>=25000){
                        $transport->sendPing($epoch);
                        $lastPing=$now;
                    }
                    // A real PONG is required even outside trading hours;
                    // market message inactivity is evaluated separately.
                    $session->tick($epoch,$now,false);
                },
                30,5000,200,static function(int $value)use(&$token):void{$token=$value;},
                $source->reconnectPolicy,
            );
            $output->writeln(json_encode([
                'status'=>$result,'accepted'=>$accepted,'source'=>$sourceName,
                'symbol'=>$symbol,'mode'=>$mode->value,
            ],JSON_THROW_ON_ERROR));
            return $result==='STOPPED'&&$accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            // Do not interpolate vendor exception messages: they might
            // include credentials or unlicensed response content.
            $output->writeln(json_encode([
                'status'=>'FAILED_PRECHECK','reason'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
