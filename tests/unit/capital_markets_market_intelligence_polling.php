<?php
declare(strict_types=1);

use DateTimeImmutable;
use Domains\CapitalMarkets\Application\Contract\MarketDataIngestionInterface;
use Domains\CapitalMarkets\Application\Contract\MarketDataProviderAvailabilityInterface;
use Domains\CapitalMarkets\Application\Contract\MarketInstrumentResolverInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSourceRepositoryInterface;
use Domains\CapitalMarkets\Application\Contract\MarketSubscriptionRepositoryInterface;
use Domains\CapitalMarkets\Application\DTO\MarketDataIngestionResult;
use Domains\CapitalMarkets\Application\DTO\ResolvedMarketInstrument;
use Domains\CapitalMarkets\Application\Service\MarketDataAdapterRegistry;
use Domains\CapitalMarkets\Application\Service\MarketSourcePollingService;
use Domains\CapitalMarkets\Domain\Contract\MarketClockInterface;
use Domains\CapitalMarkets\Domain\Contract\MarketDataAdapterInterface;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketConnectionState;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataBatch;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataCapability;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataInstrumentTarget;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketHealthPolicy;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceDescriptor;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceHealth;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceRole;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscription;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionId;
use Domains\CapitalMarkets\Domain\MarketData\MarketSubscriptionStatus;
use Domains\CapitalMarkets\Domain\MarketData\RateLimitPolicy;
use Domains\CapitalMarkets\Domain\MarketData\RawMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\ReconnectPolicy;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

final class CmPollClock implements MarketClockInterface
{
    public function __construct(private DateTimeImmutable $now){}
    public function now():DateTimeImmutable{return $this->now;}
    public function reliable():bool{return true;}
}

final class CmPollSources implements MarketSourceRepositoryInterface
{
    /** @var array<string,MarketSourceDescriptor> */
    public array $sources=[];
    /** @var array<string,MarketSourceHealth> */
    public array $health=[];

    public function save(string $organizationId,MarketSourceDescriptor $source):void{$this->sources[$source->id->value()]=$source;}
    public function get(string $organizationId,MarketSourceId $id):?MarketSourceDescriptor{return $this->sources[$id->value()]??null;}
    public function list(string $organizationId,bool $enabledOnly=false):array{return array_values($this->sources);}
    public function saveHealth(string $organizationId,MarketSourceHealth $health):void{$this->health[$health->sourceId->value()]=$health;}
    public function health(string $organizationId,MarketSourceId $id):?MarketSourceHealth{return $this->health[$id->value()]??null;}
}

final class CmPollSubscriptions implements MarketSubscriptionRepositoryInterface
{
    /** @var list<MarketSubscription> */
    public array $items=[];
    public function save(string $organizationId,MarketSubscription $subscription):void{$this->items[]=$subscription;}
    public function forSource(string $organizationId,MarketSourceId $sourceId,bool $activeOnly=false):array
    {
        return array_values(array_filter($this->items,static fn(MarketSubscription $item):bool=>
            $item->sourceId->equals($sourceId)&&(!$activeOnly||$item->status===MarketSubscriptionStatus::Active)
        ));
    }
}

final class CmPollResolver implements MarketInstrumentResolverInterface
{
    /** @param array<string,MarketDataInstrumentTarget> $targets */
    public function __construct(private array $targets){}
    public function resolve(string $organizationId,MarketSourceDescriptor $source,string $externalInstrument):?ResolvedMarketInstrument{return null;}
    public function target(string $organizationId,MarketSourceDescriptor $source,InstrumentId $instrumentId):?MarketDataInstrumentTarget
    {
        return $this->targets[$instrumentId->value()]??null;
    }
}

final class CmPollAvailability implements MarketDataProviderAvailabilityInterface
{
    public function __construct(public bool $value=true){}
    public function enabled(string $organizationId,MarketSourceDescriptor $source):bool{return $this->value;}
}

final class CmPollAdapter implements MarketDataAdapterInterface
{
    public int $snapshotCalls=0;
    public function __construct(private DateTimeImmutable $now){}
    public function adapterType():string{return 'fixture.poll';}
    public function getSource():string{return 'FIXTURE';}
    public function getCapabilities():array{return [MarketDataCapability::Bbo,MarketDataCapability::Volume];}
    public function supports(MarketDataCapability $capability,MarketDataInstrumentTarget $target):bool
    {
        return in_array($capability,$this->getCapabilities(),true);
    }
    public function resolveInstrument(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):?string
    {
        return $target->externalSymbol;
    }
    public function getSnapshot(string $organizationId,MarketSourceDescriptor $source,MarketDataInstrumentTarget $target):MarketDataBatch
    {
        $this->snapshotCalls++;
        return new MarketDataBatch($source->id,[
            new RawMarketEvent(
                'poll-raw-'.$this->snapshotCalls,$source->id,$source->venueId,$target->externalSymbol,'fixture.bbo',
                $this->now,$this->now,null,['bid'=>'1'],[]
            ),
        ]);
    }
    public function getHealth(string $organizationId,MarketSourceDescriptor $source):MarketSourceHealth
    {
        return new MarketSourceHealth($source->id,MarketConnectionState::Connected,null,null,0,0,true);
    }
}

final class CmPollIngestion implements MarketDataIngestionInterface
{
    public int $calls=0;
    public function __construct(private CmPollSources $sources,private MarketSourceId $sourceId){}
    public function ingest(string $organizationId,RawMarketEvent $raw):MarketDataIngestionResult
    {
        $health=$this->sources->health($organizationId,$this->sourceId);
        if($health?->connectionState!==MarketConnectionState::Active){
            throw new RuntimeException('Source health must be ACTIVE before ingestion.');
        }
        $this->calls++;
        return new MarketDataIngestionResult('ACCEPTED',true,true,true);
    }
}

$organization='org-poll';
$now=new DateTimeImmutable('2026-10-06T09:00:00+00:00');
$clock=new CmPollClock($now);
$sourceId=MarketSourceId::fromString('source:fixture');
$venueId=VenueId::fromString('venue:fixture');
$instrumentId=InstrumentId::fromString('instrument:fixture');
$instrument=new InstrumentDescriptor(
    $instrumentId,'FIX','FIX','Fixture',InstrumentFamily::TokenizedSecurity,InstrumentStatus::Active,
    null,new AssetCode('USDT'),null,null,$venueId->value(),[],$now,$now
);
$mapping=new VenueInstrument($venueId,$instrumentId,'FIXUSDT',VenueInstrumentStatus::Active,4,8);
$target=new MarketDataInstrumentTarget($instrument,$mapping,'FIXUSDT');
$source=new MarketSourceDescriptor(
    $sourceId,$venueId,'fixture.poll',true,10,[MarketSourceRole::TradingSource],null,
    new RateLimitPolicy(10,1),new ReconnectPolicy(),new MarketHealthPolicy()
);

$sources=new CmPollSources();$sources->save($organization,$source);
$subscriptions=new CmPollSubscriptions();
$subscriptions->save($organization,new MarketSubscription(
    MarketSubscriptionId::fromString('sub:bbo'),$sourceId,$venueId,$instrumentId,MarketEventType::Bbo,
    MarketSubscriptionStatus::Active,$now
));
$subscriptions->save($organization,new MarketSubscription(
    MarketSubscriptionId::fromString('sub:volume'),$sourceId,$venueId,$instrumentId,MarketEventType::Volume,
    MarketSubscriptionStatus::Active,$now
));
$adapter=new CmPollAdapter($now);
$availability=new CmPollAvailability(true);
$ingestion=new CmPollIngestion($sources,$sourceId);
$service=new MarketSourcePollingService(
    $sources,$subscriptions,new CmPollResolver([$instrumentId->value()=>$target]),
    new MarketDataAdapterRegistry([$adapter]),$availability,$ingestion,$clock
);

$result=$service->poll($organization,$sourceId);
$assert($result->status==='OK','Healthy provider poll must be OK.');
$assert($result->targets===1,'Two subscriptions for one instrument must coalesce to one provider snapshot.');
$assert($adapter->snapshotCalls===1,'Provider snapshot was called more than once for the same instrument.');
$assert($result->rawEvents===1&&$result->accepted===1&&$result->failed===0,'Provider poll counters drifted.');
$assert($ingestion->calls===1,'Provider poll did not feed raw event into canonical ingestion.');
$assert($sources->health($organization,$sourceId)?->connectionState===MarketConnectionState::Active,'Successful provider poll did not mark source ACTIVE.');

$availability->value=false;
$disabled=$service->poll($organization,$sourceId);
$assert($disabled->status==='PROVIDER_DISABLED','Provider feature gate was ignored.');
$assert($adapter->snapshotCalls===1,'Disabled provider must not perform HTTP snapshot work.');

$availability->value=true;
$unknownId=InstrumentId::fromString('instrument:missing');
$subscriptions->save($organization,new MarketSubscription(
    MarketSubscriptionId::fromString('sub:missing'),$sourceId,$venueId,$unknownId,MarketEventType::Bbo,
    MarketSubscriptionStatus::Active,$now
));
$degraded=$service->poll($organization,$sourceId);
$assert($degraded->status==='DEGRADED','Unresolved subscription target must degrade the polling result.');
$assert($degraded->failed===1,'Unresolved subscription target must count as a failure.');
$assert($adapter->snapshotCalls===2,'Valid target should still be polled when another subscription is unresolved.');

echo "Capital Markets provider polling passed.\n";
