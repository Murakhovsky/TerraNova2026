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
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotMarketDataAdapter;
use Domains\CapitalMarkets\Infrastructure\MarketData\Adapter\Binance\BinanceSpotPayloadParser;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\BinanceSpotBboProtocol;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\NativeWebSocketTransport;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\StreamingFencedIngestionGateway;
use Domains\CapitalMarkets\Infrastructure\MarketData\Streaming\MysqlStreamingProviderVerification;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Explicitly enabled, one-BBO-symbol Binance Spot streaming pilot.
 * Not a replacement for provider-wide production streaming/sharding.
 */
#[AsCommand(name:'cos:capital-markets:market-data:stream:binance-spot',
    description:'Run the feature-gated, single-symbol Binance Spot bookTicker WebSocket pilot.')]
final class CapitalMarketsBinanceSpotStreamingCommand extends Command
{
    public function __construct(
        private readonly CapitalMarketsFeatureGate $flags,
        private readonly MarketSourceRepositoryInterface $sources,
        private readonly MarketSubscriptionRepositoryInterface $subscriptions,
        private readonly MarketInstrumentResolverInterface $resolver,
        private readonly BinanceSpotPayloadParser $parser,
        private readonly StreamingWorkerRunner $runner,
        private readonly StreamingFencedIngestionGateway $ingestion,
        private readonly MysqlStreamingProviderVerification $verification,
    ){
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addArgument('organization',InputArgument::REQUIRED,'Organization ID')
            ->addArgument('source',InputArgument::REQUIRED,'Configured Binance Spot source ID')
            ->addOption('seconds',null,InputOption::VALUE_REQUIRED,'Duration (0 means until SIGTERM)','60');
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
        if(getenv('COS_CM_STREAMING_BINANCE_SPOT_PILOT')!=='1'
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::DomainEnabled,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketData,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataBinance,$org)
            ||!$this->flags->enabled(CapitalMarketsFeatureFlag::MarketDataStreaming,$org)){
            $output->writeln('STREAMING_DISABLED');
            return Command::FAILURE;
        }
        if($seconds===0 && !function_exists('pcntl_async_signals')){
            $output->writeln('UNBOUNDED_RUN_REQUIRES_PCNTL');
            return Command::FAILURE;
        }
        try{
            $sourceId=MarketSourceId::fromString($sourceName);
            $source=$this->sources->get($org,$sourceId);
            if($source===null||!$source->enabled
                ||$source->adapterType!==BinanceSpotMarketDataAdapter::ADAPTER_TYPE
                ||$source->venueId===null){
                throw new \DomainException('Enabled Binance Spot source with venue mapping required.');
            }
            $active=$this->subscriptions->forSource($org,$sourceId,true);
            if(count($active)!==1||$active[0]->dataType!==MarketEventType::Bbo){
                throw new \DomainException('Pilot requires exactly one active BBO subscription on the source.');
            }
            $sub=$active[0];
            $target=$this->resolver->target($org,$source,$sub->instrumentId);
            if($target===null||$target->venueInstrument===null
                ||!$target->venueInstrument->venueId->equals($source->venueId)){
                throw new \DomainException('Binance venue instrument mapping unavailable.');
            }
            $symbol=strtoupper($target->externalSymbol);
            $key=StreamingSubscriptionPlanner::key($sub);
            $protocol=new BinanceSpotBboProtocol($sourceId,$source->venueId,$key,$symbol,$this->parser);
            $session=new StreamingSessionCoordinator(
                new StreamingRecoveryGate(),new StreamingSubscriptionReconciler(),
                new StreamingHeartbeatWatchdog(180000,120000),
            );
            $clock=static fn():int=>intdiv(hrtime(true),1000000);
            $workerId='binance-bbo-'.bin2hex(random_bytes(10));
            $shard='binance-spot-single-bbo';
            $leaseToken=0;
            $accepted=0;
            $verifiedInstrumentId=$sub->instrumentId->value();
            $transport=new NativeWebSocketTransport(
                'wss://data-stream.binance.vision/ws',
                \Closure::fromCallable([$protocol,'subscribeFrames']),
                function(string $payload,int $epoch)use(
                    $protocol,$session,$clock,$org,$sourceName,$shard,$workerId,&$leaseToken,&$accepted,,
                    $verifiedInstrumentId):void{
                    $decoded=$protocol->decode($payload,new DateTimeImmutable('now',new DateTimeZone('UTC')));
                    if($decoded['kind']==='ack'){
                        $session->subscribed($epoch,$protocol->key());
                        $this->verification->acknowledged(
                            $org,$sourceName,$shard,$workerId,$leaseToken,$epoch,
                            'BINANCE_SPOT',$verifiedInstrumentId
                        );
                        return;
                    }
                    $session->message($epoch,$clock());
                    if($session->status()==='SYNCING'){
                        // bookTicker is a complete quote, not a depth delta.
                        $session->verifiedSnapshot($epoch,$decoded['update_id'],true);
                        $session->verifiedRecovery($epoch,true,true);
                    }elseif($session->trusted()){
                        $session->verifiedDelta($epoch,$decoded['update_id'],true,true);
                    }else{
                        throw new \DomainException('Untrusted Binance Spot stream cannot publish.');
                    }
                    $result=$this->ingestion->ingest(
                        $org,$sourceName,$shard,$workerId,$leaseToken,$decoded['raw'],
                    );
                    if(!in_array($result->status,['ACCEPTED','DUPLICATE'],true)){
                        throw new \DomainException('Binance canonical ingestion rejected the stream observation.');
                    }
                    if($result->status==='ACCEPTED'){
                        $this->verification->acceptedQuote(
                            $org,$sourceName,$shard,$workerId,$leaseToken,$epoch,$result
                        );
                        $accepted++;
                    }
                },
                2097152,
                function(string $kind,int $epoch)use($session,$clock):void{
                    $session->pong($epoch,$clock());
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
                static function()use(&$stop,$clock,$started,$seconds):bool{
                    return $stop || ($seconds>0&&$clock()-$started>=$seconds*1000);
                },
                static function(int $epoch)use($session,$clock):void{
                    $session->tick($epoch,$clock(),true);
                },
                30,5000,200,static function(int $token)use(&$leaseToken):void{$leaseToken=$token;},
                $source->reconnectPolicy,
            );
            $output->writeln(json_encode([
                'status'=>$status,'accepted'=>$accepted,'source'=>$sourceName,'symbol'=>$symbol,
            ],JSON_THROW_ON_ERROR));
            return $status==='STOPPED' && $accepted>0?Command::SUCCESS:Command::FAILURE;
        }catch(Throwable $error){
            $output->writeln(json_encode([
                'status'=>'FAILED_PRECHECK','reason'=>get_class($error),
            ],JSON_THROW_ON_ERROR));
            return Command::FAILURE;
        }
    }
}
