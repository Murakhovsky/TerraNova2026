<?php
declare(strict_types=1);

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\CanonicalMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Application\Contract\MarketDataDecoderInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketPartitionLockInterface;
use Domains\CapitalMarkets\Application\Contract\MarketQualityMetricRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketStateRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\RawMarketEventRepositoryInterface;
use Domains\CapitalMarkets\Application\DTO\DecodedMarketEvent;
use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Application\Service\MarketDataDecoderRegistry;
use Domains\CapitalMarkets\Application\Service\MarketDataIngestionService;
use Domains\CapitalMarkets\Application\Service\MarketDataNormalizer;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSequencePolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\Service\MarketDataQualityEngine;
use Domains\CapitalMarkets\Domain\Service\MarketStateEngine;
use Domains\CapitalMarkets\Domain\Service\OrderBookRebuilder;
use Domains\CapitalMarkets\Domain\Service\ReferenceMarketStateEngine;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Kernel\Transaction\Contract\TransactionManagerInterface;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

final class CmTestClock implements MarketClockInterface
{
    public function __construct(public DateTimeImmutable $time,private bool $isReliable=true){}
    public function now():DateTimeImmutable{return $this->time;}
    public function reliable():bool{return $this->isReliable;}
}

final class CmTestTransactions implements TransactionManagerInterface
{
    private bool $active=false;
    public function transactional(callable $operation):mixed
    {
        if($this->active)return $operation();
        $this->active=true;
        try{return $operation();}finally{$this->active=false;}
    }
    public function isActive():bool{return $this->active;}
    public function afterCommit(callable $callback):void{$callback();}
}

final class CmTestLock implements MarketPartitionLockInterface
{
    /** @var list<string> */
    public array $keys=[];
    public function synchronized(string $partitionKey,callable $criticalSection):mixed
    {
        $this->keys[]=$partitionKey;
        return $criticalSection();
    }
}

final class CmTestEvents implements CapitalMarketsEventPublisherInterface
{
    /** @var list<AbstractCapitalMarketsEvent> */
    public array $events=[];
    public function publish(AbstractCapitalMarketsEvent $event,?string $correlationId=null,?int $actorId=null):void
    {
        $this->events[]=$event;
    }
}

final class CmTestSourceRepository implements MarketSourceRepositoryInterface
{
    /** @var array<string,MarketSourceDescriptor> */
    public array $sources=[];
    /** @var array<string,MarketSourceHealth> */
    public array $health=[];
    public function save(string $organizationId,MarketSourceDescriptor $source):void{$this->sources[$source->id->value()]=$source;}
    public function get(string $organizationId,MarketSourceId $id):?MarketSourceDescriptor{return $this->sources[$id->value()]??null;}
    public function list(string $organizationId,bool $enabledOnly=false):array
    {
        return array_values(array_filter($this->sources,static fn(MarketSourceDescriptor $source):bool=>!$enabledOnly||$source->enabled));
    }
    public function saveHealth(string $organizationId,MarketSourceHealth $health):void{$this->health[$health->sourceId->value()]=$health;}
    public function health(string $organizationId,MarketSourceId $id):?MarketSourceHealth{return $this->health[$id->value()]??null;}
}

final class CmTestRawRepository implements RawMarketEventRepositoryInterface
{
    /** @var array<string,RawMarketEvent> */
    public array $events=[];
    public function append(string $organizationId,RawMarketEvent $event):bool
    {
        if(isset($this->events[$event->eventId]))return false;
        $this->events[$event->eventId]=$event;
        return true;
    }
    public function range(string $organizationId,MarketSourceId $sourceId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn(RawMarketEvent $event):bool=>$event->sourceId->equals($sourceId)
                &&$event->receivedAt>=$from&&$event->receivedAt<=$to
        )),0,$limit);
    }
}

final class CmTestCanonicalRepository implements CanonicalMarketEventRepositoryInterface
{
    /** @var array<string,CanonicalMarketEvent> */
    public array $events=[];
    /** @var array<string,bool> */
    private array $fingerprints=[];
    public function append(string $organizationId,CanonicalMarketEvent $event):bool
    {
        $fingerprint=$event->fingerprint();
        if(isset($this->events[$event->eventId])||isset($this->fingerprints[$fingerprint]))return false;
        $this->events[$event->eventId]=$event;
        $this->fingerprints[$fingerprint]=true;
        return true;
    }
    public function history(string $organizationId,InstrumentId $instrumentId,DateTimeImmutable $from,DateTimeImmutable $to,int $limit=1000):array
    {
        return array_slice(array_values(array_filter(
            $this->events,
            static fn(CanonicalMarketEvent $event):bool=>$event->instrumentId->equals($instrumentId)
                &&$event->timestamps->sourceTimestamp>=$from&&$event->timestamps->sourceTimestamp<=$to
        )),0,$limit);
    }
}

final class CmTestQualityRepository implements MarketQualityMetricRepositoryInterface
{
    /** @var list<array{event:CanonicalMarketEvent,assessment:MarketDataQualityAssessment}> */
    public array $rows=[];
    public function append(string $organizationId,CanonicalMarketEvent $event,MarketDataQualityAssessment $assessment,DateTimeImmutable $recordedAt):void
    {
        $this->rows[]=['event'=>$event,'assessment'=>$assessment];
    }
}

final class CmTestStateRepository implements MarketStateRepositoryInterface
{
    /** @var array<string,MarketState> */
    public array $states=[];
    /** @var array<string,ReferenceMarketState> */
    public array $references=[];

    public function save(string $organizationId,MarketState $state):void{$this->states[$state->key()]=$state;}
    public function get(string $organizationId,VenueId $venueId,InstrumentId $instrumentId):?MarketState
    {
        return $this->states[$venueId->value().'|'.$instrumentId->value()]??null;
    }
    public function list(string $organizationId,int $limit=200):array{return array_slice(array_values($this->states),0,$limit);}
    public function saveReference(string $organizationId,ReferenceMarketState $state):void
    {
        $this->references[$state->sourceId->value().'|'.$state->instrumentId->value()]=$state;
    }
    public function getReference(string $organizationId,MarketSourceId $sourceId,InstrumentId $instrumentId):?ReferenceMarketState
    {
        return $this->references[$sourceId->value().'|'.$instrumentId->value()]??null;
    }
    public function listReferences(string $organizationId,int $limit=200):array{return array_slice(array_values($this->references),0,$limit);}
}

final class CmTestResolver implements MarketInstrumentResolverInterface
{
    /** @param array<string,ResolvedMarketInstrument> $map */
    public function __construct(private array $map){}
    public function resolve(string $organizationId,MarketSourceDescriptor $source,string $externalInstrument):?ResolvedMarketInstrument
    {
        return $this->map[$externalInstrument]??null;
    }
}

final class CmTestDecoder implements MarketDataDecoderInterface
{
    public function adapterType():string{return 'test.market';}
    public function decode(MarketSourceDescriptor $source,RawMarketEvent $event):DecodedMarketEvent
    {
        return new DecodedMarketEvent(
            MarketEventType::Bbo,
            $event->externalInstrument,
            $event->providerTimestamp??$event->receivedAt,
            $event->sequence,
            $event->rawPayload,
            $event->mode,
            MarketStatus::Open,
            MarketSession::Regular,
            ReferenceType::Nbbo,
        );
    }
}

$now=new DateTimeImmutable('2026-10-06T08:00:00.200000+00:00');
$clock=new CmTestClock($now);
$organization='org-test';
$venueId=VenueId::fromString('venue:test');
$tokenId=InstrumentId::fromString('instrument:aaplx');
$referenceId=InstrumentId::fromString('instrument:aapl');

$token=new InstrumentDescriptor(
    $tokenId,'AAPLX','AAPLX','Apple xStock',InstrumentFamily::TokenizedSecurity,InstrumentStatus::Active,
    null,new AssetCode('USDT'),null,null,$venueId->value(),[],
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),new DateTimeImmutable('2026-10-01T00:00:00+00:00')
);
$reference=new InstrumentDescriptor(
    $referenceId,'AAPL','AAPL','Apple Inc.',InstrumentFamily::Equity,InstrumentStatus::Active,
    null,new AssetCode('USD'),null,'US',null,[],
    new DateTimeImmutable('2026-10-01T00:00:00+00:00'),new DateTimeImmutable('2026-10-01T00:00:00+00:00')
);
$tokenMapping=new VenueInstrument($venueId,$tokenId,'AAPLXUSDT',VenueInstrumentStatus::Active,4,8);

$ages=[];
foreach(MarketEventType::cases() as $type)$ages[$type->value]=60000;
$qualityPolicy=new MarketDataQualityPolicy(
    $ages,1000,1000,1000,5000,1000,false,
    [MarketEventType::Bbo->value=>MarketSequencePolicy::Monotonic]
);

$tradingSource=new MarketSourceDescriptor(
    MarketSourceId::fromString('source:test-trading'),$venueId,'test.market',true,10,[MarketSourceRole::TradingSource],null,
    new RateLimitPolicy(100,1),new ReconnectPolicy(),new MarketHealthPolicy(),[],'TEST',$qualityPolicy
);
$referenceSource=new MarketSourceDescriptor(
    MarketSourceId::fromString('source:test-reference'),null,'test.market',true,20,[MarketSourceRole::ReferenceSource],null,
    new RateLimitPolicy(100,1),new ReconnectPolicy(),new MarketHealthPolicy(),[],'TEST',$qualityPolicy
);

$sources=new CmTestSourceRepository();
$sources->save($organization,$tradingSource);
$sources->save($organization,$referenceSource);
$sources->saveHealth($organization,new MarketSourceHealth(
    $tradingSource->id,MarketConnectionState::Active,$now,$now,0,0,true
));
$sources->saveHealth($organization,new MarketSourceHealth(
    $referenceSource->id,MarketConnectionState::Active,$now,$now,0,0,true
));

$resolver=new CmTestResolver([
    'AAPLXUSDT'=>new ResolvedMarketInstrument($token,$tokenMapping),
    'AAPL'=>new ResolvedMarketInstrument($reference,null),
]);
$normalizer=new MarketDataNormalizer($resolver,$clock);
$raws=new CmTestRawRepository();
$canonical=new CmTestCanonicalRepository();
$qualityMetrics=new CmTestQualityRepository();
$states=new CmTestStateRepository();
$events=new CmTestEvents();
$locks=new CmTestLock();
$transactions=new CmTestTransactions();

$service=new MarketDataIngestionService(
    $sources,new MarketDataDecoderRegistry([new CmTestDecoder()]),$normalizer,$raws,$canonical,$qualityMetrics,$states,
    new MarketDataQualityEngine(),new MarketStateEngine(new OrderBookRebuilder()),new ReferenceMarketStateEngine(),
    $locks,$events,$transactions,$clock
);

$payload=[
    'bid_price'=>'293.48','bid_quantity'=>'12.5',
    'ask_price'=>'293.61','ask_quantity'=>'10',
];
$raw1=new RawMarketEvent(
    'raw-1',$tradingSource->id,$venueId,'AAPLXUSDT','ticker',
    new DateTimeImmutable('2026-10-06T08:00:00.100000+00:00'),
    new DateTimeImmutable('2026-10-06T08:00:00.140000+00:00'),
    '100',$payload,[],MarketDataMode::Live
);
$result1=$service->ingest($organization,$raw1);
$assert($result1->status==='ACCEPTED','Trading BBO ingestion was not accepted.');
$assert($result1->rawStored&&$result1->canonicalStored&&$result1->stateUpdated,'Trading pipeline did not persist every stage.');
$assert($result1->quality?->status->value==='TRUSTED','Fresh healthy trading BBO must be TRUSTED.');
$assert($result1->state instanceof MarketState,'Trading source must produce MarketState.');
$assert($result1->state->bestQuote?->midPrice()->value()==='293.545','Normalized BBO midpoint drifted.');
$assert($result1->state->mode===MarketDataMode::Live,'MarketState lost data mode.');
$assert(count($events->events)===1&&$events->events[0]->eventName()==='capital_markets.market_state.trusted.v1','TRUSTED transition event missing.');

$duplicateRaw=new RawMarketEvent(
    'raw-2',$tradingSource->id,$venueId,'AAPLXUSDT','ticker',
    $raw1->providerTimestamp,$raw1->receivedAt,'100',$payload,[],MarketDataMode::Live
);
$duplicate=$service->ingest($organization,$duplicateRaw);
$assert($duplicate->status==='DUPLICATE','Same canonical fingerprint must be idempotent.');
$assert(count($raws->events)===2,'Duplicate canonical event must still preserve raw evidence.');
$assert(count($canonical->events)===1,'Duplicate canonical event was persisted twice.');
$assert($states->list($organization)[0]->stateVersion===1,'Duplicate canonical event mutated current state.');

$unknownRaw=new RawMarketEvent(
    'raw-unknown',$tradingSource->id,$venueId,'NO_SUCH_SYMBOL','ticker',
    $raw1->providerTimestamp,$raw1->receivedAt,'101',$payload,[],MarketDataMode::Live
);
$unknown=$service->ingest($organization,$unknownRaw);
$assert($unknown->status==='UNKNOWN_INSTRUMENT','Unknown symbol must fail before canonical state.');
$assert(count($raws->events)===3,'Unknown symbol raw evidence was lost.');
$assert(count($canonical->events)===1,'Unknown symbol must not create canonical event.');
$assert(end($events->events)->eventName()==='capital_markets.market_data.instrument_unresolved.v1','Unknown symbol incident event missing.');

$oldPayload=[
    'bid_price'=>'293.00','bid_quantity'=>'1',
    'ask_price'=>'293.10','ask_quantity'=>'1',
];
$oldRaw=new RawMarketEvent(
    'raw-old',$tradingSource->id,$venueId,'AAPLXUSDT','ticker',
    new DateTimeImmutable('2026-10-06T08:00:00.050000+00:00'),
    new DateTimeImmutable('2026-10-06T08:00:00.180000+00:00'),
    '99',$oldPayload,[],MarketDataMode::Live
);
$old=$service->ingest($organization,$oldRaw);
$assert($old->status==='ACCEPTED'&&!$old->stateUpdated,'Out-of-order event must be historical evidence only.');
$assert(count($canonical->events)===2,'Out-of-order canonical event should remain in history.');
$current=$states->get($organization,$venueId,$tokenId);
$assert($current?->bestQuote?->midPrice()->value()==='293.545','Out-of-order event regressed current trading state.');

$referencePayload=[
    'bid_price'=>'293.32','bid_quantity'=>'7',
    'ask_price'=>'293.36','ask_quantity'=>'4',
];
$refRaw=new RawMarketEvent(
    'ref-1',$referenceSource->id,null,'AAPL','quote',
    new DateTimeImmutable('2026-10-06T08:00:00.120000+00:00'),
    new DateTimeImmutable('2026-10-06T08:00:00.150000+00:00'),
    '200',$referencePayload,[],MarketDataMode::Live
);
$refResult=$service->ingest($organization,$refRaw);
$assert($refResult->status==='ACCEPTED'&&$refResult->state instanceof ReferenceMarketState,'Reference source must produce ReferenceMarketState.');
$assert($refResult->state->session===MarketSession::Regular,'Reference market session was lost.');
$assert($refResult->state->lastRegularMarketQuote!==null,'Regular reference quote was not preserved.');
$assert($refResult->state->lastEventFingerprint!==null,'Reference idempotency watermark missing.');

$oldRefRaw=new RawMarketEvent(
    'ref-old',$referenceSource->id,null,'AAPL','quote',
    new DateTimeImmutable('2026-10-06T08:00:00.010000+00:00'),
    new DateTimeImmutable('2026-10-06T08:00:00.190000+00:00'),
    '199',[
        'bid_price'=>'292.00','bid_quantity'=>'1',
        'ask_price'=>'292.10','ask_quantity'=>'1',
    ],[],MarketDataMode::Live
);
$oldRef=$service->ingest($organization,$oldRefRaw);
$assert(!$oldRef->stateUpdated,'Out-of-order reference event must not regress current reference state.');
$referenceCurrent=$states->getReference($organization,$referenceSource->id,$referenceId);
$assert($referenceCurrent?->currentQuote?->midPrice()->value()==='293.34','Reference current state regressed.');

$assert(count($qualityMetrics->rows)===4,'Quality metrics count drifted.');
$assert(count($locks->keys)===5,'Every canonicalizable event must pass through a partition lock.');

echo "Capital Markets Market Intelligence ingestion passed.\n";
