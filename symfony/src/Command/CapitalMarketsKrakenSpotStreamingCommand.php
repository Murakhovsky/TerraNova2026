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
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\StreamingHeartbeatWatchdog;
use Domains\CapitalMarkets\Domain\MarketData\StreamingRecoveryGate;
use Domains\CapitalMarkets\Domain\MarketData\StreamingSubscriptionReconciler;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Kraken\KrakenSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\KrakenSpotTickerProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\NativeWebSocketTransport;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;
use Domains\CapitalMarkets\Infrastructure\MarketData\VenueMarketStatusResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Public Kraken Spot v2 ticker BBO canary. Uses persisted Kraken symbol
 * mapping, including xStocks ONLY when listed by the source itself.
 * No order execution, no synthetic parent-equity marks.
 */
#[AsCommand(
    name:'cos:capital-markets:market-data:stream:kraken-spot',
    description:'Feature-gated Kraken Spot / xStocks public ticker BBO canary.',
)]
final class CapitalMarketsKrakenSpotStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketSubscriptionRepositoryInterface $subscriptions,
        private readonly MarketInstrumentResolverInterface $resolver,
        private readonly VenueMarketStatusResolver $marketStatus,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Configured Kraken Spot source')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'Duration, 0 for SIGTERM-controlled run','60');
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
        if(getenv('COS_CM_STREAMING_KRAKEN_SPOT_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataKraken,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)){
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
                ||$source->adapterType!==KrakenSpotMarketDataAdapter::ADAPTER_TYPE
                ||$source->venueId===null){
                throw new \DomainException('Enabled Kraken Spot source required.');
            }
            $subscriptions=$this->subscriptions->forSource($org,$sourceId,true);
            if(count($subscriptions)!==1||$subscriptions[0]->dataType!==MarketEventType::Bbo){
                throw new \DomainException('Kraken canary requires one BBO subscription only.');
            }
            $target=$this->resolver->target($org,$source,$subscriptions[0]->instrumentId);
            if($target===null||$target->venueInstrument===null
                ||!$target->venueInstrument->venueId->equals($source->venueId)){
                throw new \DomainException('Missing verified Kraken venue pair mapping.');
            }
            $symbol=$target->externalSymbol; // Provider case and slash must be preserved.
            $key=StreamingSubscriptionPlanner::key($subscriptions[0]);
            $protocol=new KrakenSpotTickerProtocol($sourceId,$source->venueId,$key,$symbol);
            $session=new StreamingSessionCoordinator(
                new StreamingRecoveryGate(),new StreamingSubscriptionReconciler(),
                new StreamingHeartbeatWatchdog(45000,120000),
            );
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $workerId='kraken-ticker-'.bin2hex(random_bytes(10));
            $shard='kraken-spot-single-bbo';
            $leaseToken=0;
            $accepted=0;
            $expectMarketMessages=true;
            $transport=new NativeWebSocketTransport(
                'wss://ws.kraken.com/v2',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $epoch) use (
                    $protocol,$session,$clock,$org,$sourceName,$shard,$workerId,
                    $target,&$leaseToken,&$accepted,&$expectMarketMessages,
                ):void{
                    $received=new DateTimeImmutable('now',new DateTimeZone('UTC'));
                    $status=$this->marketStatus->resolve($target->venueInstrument,$received);
                    $expectMarketMessages=$status!==MarketStatus::Closed;
                    $parsed=$protocol->decode($payload,$received,$status->value);
                    if($parsed['kind']==='heartbeat'){
                        $session->pong($epoch,$clock());
                        return;
                    }
                    if($parsed['kind']==='ack'){
                        $session->subscribed($epoch,$protocol->key());
                        return;
                    }
                    if($parsed['kind']==='reset'){
                        $session->disconnected($epoch,'KRAKEN_TICKER_TIME_RESET');
                        return;
                    }
                    $session->message($epoch,$clock());
                    if($session->status()==='SYNCING'){
                        $session->verifiedSnapshot($epoch,$parsed['timestamp'],true);
                        $session->verifiedRecovery($epoch,true,true);
                    }elseif($session->trusted()){
                        $session->verifiedDelta($epoch,$parsed['timestamp'],true,true);
                    }else{
                        throw new \DomainException('Untrusted Kraken quote cannot be published.');
                    }
                    $stored=$this->ingestion->ingest(
                        $org,$sourceName,$shard,$workerId,$leaseToken,$parsed['raw'],
                    );
                    if(!in_array($stored->status,['ACCEPTED','DUPLICATE'],true)){
                        throw new \DomainException('Kraken canonical ingestion rejected quote.');
                    }
                    if($stored->status==='ACCEPTED')$accepted++;
                },
                2097152,
                function(string $kind,int $epoch)use($session,$clock):void{
                    if($kind==='PING'||$kind==='PONG')$session->pong($epoch,$clock());
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
                static function(int $epoch)use($session,$clock,&$expectMarketMessages):void{
                    $session->tick($epoch,$clock(),$expectMarketMessages);
                },
                30,5000,200,static function(int $token)use(&$leaseToken):void{$leaseToken=$token;},
                $source->reconnectPolicy,
            );
            $output->writeln(json_encode([
                'status'=>$result,'accepted'=>$accepted,'source'=>$sourceName,'symbol'=>$symbol,
            ],JSON_THROW_ON_ERROR));
            return $result==='STOPPED'&&$accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED_PRECHECK','reason'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
