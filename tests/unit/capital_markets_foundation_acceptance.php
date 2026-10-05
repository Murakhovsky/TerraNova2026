<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditTrail;
use Domains\CapitalMarkets\Application\Command\CreateInstrument;
use Domains\CapitalMarkets\Application\Command\CreateRelationship;
use Domains\CapitalMarkets\Application\Command\CreateVenue;
use Domains\CapitalMarkets\Application\Command\RegisterVenueInstrument;
use Domains\CapitalMarkets\Application\Contract\CapitalMarketsEventPublisherInterface;
use Domains\CapitalMarkets\Application\Query\GetInstrument;
use Domains\CapitalMarkets\Application\Query\GetVenueInstruments;
use Domains\CapitalMarkets\Application\Service\CapitalMarketsFoundationService;
use Domains\CapitalMarkets\Domain\Contract\InstrumentRepository;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Contract\VenueRepository;
use Domains\CapitalMarkets\Domain\Event\AbstractCapitalMarketsEvent;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Platform\Audit\Contract\AuditSinkInterface;
use Platform\Audit\Model\ActivityRecord;
use Platform\Audit\Service\AuditRecorder;
use Kernel\Transaction\Contract\TransactionManagerInterface;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

final class CmMemoryInstrumentRepository implements InstrumentRepository
{
    /** @var array<string,array<string,InstrumentDescriptor>> */
    private array $items=[];
    /** @var array<string,array<string,list<InstrumentIdentifier>>> */
    private array $identifiers=[];

    public function save(string $organizationId,InstrumentDescriptor $instrument,array $identifiers):void
    {
        $this->items[$organizationId][$instrument->id->value()]=$instrument;
        $this->identifiers[$organizationId][$instrument->id->value()]=array_values($identifiers);
    }

    public function get(string $organizationId,InstrumentId $id):?InstrumentDescriptor
    {
        return $this->items[$organizationId][$id->value()]??null;
    }

    public function findByIdentifier(string $organizationId,InstrumentIdentifier $identifier):?InstrumentDescriptor
    {
        foreach($this->identifiers[$organizationId]??[] as $instrumentId=>$identifiers){
            foreach($identifiers as $candidate){
                if($candidate->uniquenessKey()===$identifier->uniquenessKey()){
                    return $this->items[$organizationId][$instrumentId]??null;
                }
            }
        }
        return null;
    }

    public function list(string $organizationId,array $filters=[],int $limit=100):array
    {
        $items=array_values($this->items[$organizationId]??[]);
        $items=array_values(array_filter($items,static function(InstrumentDescriptor $item)use($filters):bool{
            if(($filters['family']??'')!==''&&$item->family->value!==$filters['family'])return false;
            if(($filters['status']??'')!==''&&$item->status->value!==$filters['status'])return false;
            if(($filters['q']??'')!==''){
                $q=strtolower($filters['q']);
                if(!str_contains(strtolower($item->symbol),$q)&&!str_contains(strtolower($item->name),$q))return false;
            }
            return true;
        }));
        usort($items,static fn(InstrumentDescriptor $a,InstrumentDescriptor $b):int=>$a->canonicalSymbol<=>$b->canonicalSymbol);
        return array_slice($items,0,$limit);
    }

    public function identifiers(string $organizationId,InstrumentId $id):array
    {
        return $this->identifiers[$organizationId][$id->value()]??[];
    }
}

final class CmMemoryRelationshipRepository implements RelationshipRepository
{
    /** @var array<string,array<string,EconomicRelationship>> */
    private array $items=[];

    public function save(string $organizationId,EconomicRelationship $relationship):void
    {
        foreach($this->items[$organizationId]??[] as $existing){
            if($existing->key()===$relationship->key()&&!$existing->equals($relationship)){
                throw new DomainException('Duplicate relationship edge.');
            }
        }
        $this->items[$organizationId][$relationship->id->value()]=$relationship;
    }

    public function get(string $organizationId,RelationshipId $id):?EconomicRelationship
    {
        return $this->items[$organizationId][$id->value()]??null;
    }

    public function forInstrument(string $organizationId,InstrumentId $instrumentId):array
    {
        return array_values(array_filter(
            $this->items[$organizationId]??[],
            static fn(EconomicRelationship $r):bool=>
                $r->sourceInstrument->equals($instrumentId)||$r->targetInstrument->equals($instrumentId)
        ));
    }

    public function list(string $organizationId,int $limit=200):array
    {
        return array_slice(array_values($this->items[$organizationId]??[]),0,$limit);
    }
}

final class CmMemoryVenueRepository implements VenueRepository
{
    /** @var array<string,array<string,VenueDescriptor>> */
    private array $venues=[];
    /** @var array<string,array<string,list<VenueCapability>>> */
    private array $capabilities=[];
    /** @var array<string,array<string,VenueInstrument>> */
    private array $mappings=[];

    public function save(string $organizationId,VenueDescriptor $venue,array $capabilities):void
    {
        $this->venues[$organizationId][$venue->id->value()]=$venue;
        $this->capabilities[$organizationId][$venue->id->value()]=array_values($capabilities);
    }

    public function get(string $organizationId,VenueId $id):?VenueDescriptor
    {
        return $this->venues[$organizationId][$id->value()]??null;
    }

    public function list(string $organizationId,int $limit=100):array
    {
        return array_slice(array_values($this->venues[$organizationId]??[]),0,$limit);
    }

    public function capabilities(string $organizationId,VenueId $id):array
    {
        return $this->capabilities[$organizationId][$id->value()]??[];
    }

    public function registerInstrument(string $organizationId,VenueInstrument $mapping):void
    {
        $this->mappings[$organizationId][$mapping->key()]=$mapping;
    }

    public function instruments(string $organizationId,VenueId $venueId):array
    {
        return array_values(array_filter(
            $this->mappings[$organizationId]??[],
            static fn(VenueInstrument $m):bool=>$m->venueId->equals($venueId)
        ));
    }

    public function venuesForInstrument(string $organizationId,InstrumentId $instrumentId):array
    {
        return array_values(array_filter(
            $this->mappings[$organizationId]??[],
            static fn(VenueInstrument $m):bool=>$m->instrumentId->equals($instrumentId)
        ));
    }
}

final class CmMemoryAuditSink implements AuditSinkInterface
{
    /** @var list<ActivityRecord> */
    public array $records=[];
    public function append(ActivityRecord $record):void{$this->records[]=$record;}
}

final class CmMemoryEventPublisher implements CapitalMarketsEventPublisherInterface
{
    /** @var list<AbstractCapitalMarketsEvent> */
    public array $events=[];
    /** @var list<?string> */
    public array $correlations=[];
    /** @var list<?int> */
    public array $actors=[];

    public function publish(
        AbstractCapitalMarketsEvent $event,
        ?string $correlationId=null,
        ?int $actorId=null,
    ):void{
        $this->events[]=$event;
        $this->correlations[]=$correlationId;
        $this->actors[]=$actorId;
    }
}

final class CmMemoryTransactionManager implements TransactionManagerInterface
{
    private bool $active=false;
    public int $transactions=0;

    public function transactional(callable $operation):mixed
    {
        if($this->active)return $operation();
        $this->active=true;
        $this->transactions++;
        try{
            return $operation();
        }finally{
            $this->active=false;
        }
    }

    public function isActive():bool{return $this->active;}

    public function afterCommit(callable $callback):void
    {
        $callback();
    }
}

$instruments=new CmMemoryInstrumentRepository();
$relationships=new CmMemoryRelationshipRepository();
$venues=new CmMemoryVenueRepository();
$auditSink=new CmMemoryAuditSink();
$events=new CmMemoryEventPublisher();
$transactions=new CmMemoryTransactionManager();

$service=new CapitalMarketsFoundationService(
    $instruments,
    $relationships,
    $venues,
    new CapitalMarketsAuditTrail(new AuditRecorder($auditSink)),
    $events,
    $transactions,
);

$org='tenant-capital-markets';
$actor=42;

$aapl=$service->createInstrument(new CreateInstrument($org,$actor,'corr-aapl',[
    'id'=>'instrument:aapl',
    'symbol'=>'AAPL',
    'canonical_symbol'=>'AAPL',
    'name'=>'Apple Inc.',
    'family'=>'equity',
    'status'=>'ACTIVE',
    'currency'=>'USD',
    'quote_asset'=>'USD',
    'issuer_reference'=>'Apple Inc.',
    'jurisdiction'=>'US',
    'identifiers'=>[
        ['type'=>'TICKER','value'=>'AAPL','source'=>'NASDAQ'],
        ['type'=>'ISIN','value'=>'US0378331005'],
    ],
]));

$aaplx=$service->createInstrument(new CreateInstrument($org,$actor,'corr-aaplx',[
    'id'=>'instrument:aaplx',
    'symbol'=>'AAPLx',
    'canonical_symbol'=>'AAPLX',
    'name'=>'Tokenized Apple exposure',
    'family'=>'tokenized_security',
    'status'=>'ACTIVE',
    'currency'=>'USD',
    'quote_asset'=>'USDT',
    'issuer_reference'=>'Tokenized Provider',
    'jurisdiction'=>'EU',
    'identifiers'=>[
        ['type'=>'PROVIDER_ID','value'=>'AAPLx','source'=>'provider-a'],
        ['type'=>'EXCHANGE_SYMBOL','value'=>'AAPLx','source'=>'venue-a'],
    ],
]));

$relationship=$service->createRelationship(new CreateRelationship($org,$actor,'corr-rel',[
    'id'=>'relationship:aaplx-represents-aapl',
    'source_instrument'=>'instrument:aaplx',
    'target_instrument'=>'instrument:aapl',
    'type'=>'REPRESENTS',
    'strength'=>'EXACT',
    'status'=>'ACTIVE',
    'metadata'=>['evidence'=>'issuer-reference'],
]));

$venue=$service->createVenue(new CreateVenue($org,$actor,'corr-venue',[
    'id'=>'venue:tokenized-a',
    'name'=>'Tokenized Venue A',
    'code'=>'TVA',
    'type'=>'tokenized_securities_venue',
    'status'=>'ACTIVE',
    'jurisdiction'=>'EU',
    'timezone'=>'UTC',
    'base_url_reference'=>'integration:venue-a',
    'capabilities'=>['MARKET_DATA','TRADING'],
]));

$mapping=$service->registerVenueInstrument(new RegisterVenueInstrument($org,$actor,'corr-map','venue:tokenized-a',[
    'instrument_id'=>'instrument:aaplx',
    'venue_symbol'=>'AAPLx',
    'status'=>'ACTIVE',
    'price_precision'=>4,
    'quantity_precision'=>8,
    'minimum_quantity'=>'0.001',
    'minimum_notional'=>'10',
]));

$loaded=$service->getInstrument(new GetInstrument($org,'instrument:aaplx'));
$assert($loaded!==null,'AAPLx was not readable after creation.');
$assert(($loaded['symbol']??null)==='AAPLx','AAPLx identity drifted.');
$assert(count($loaded['relationships']??[])===1,'AAPLx relationship was not readable.');
$assert(($loaded['relationships'][0]['type']??null)==='REPRESENTS','AAPLx relationship type drifted.');
$assert(($loaded['relationships'][0]['target_instrument']['symbol']??null)==='AAPL','AAPLx relationship target drifted.');
$assert(count($loaded['venues']??[])===1,'AAPLx venue mapping was not readable.');
$assert(($loaded['venues'][0]['venue_symbol']??null)==='AAPLx','Venue symbol drifted.');

$venueMappings=$service->getVenueInstruments(new GetVenueInstruments($org,'venue:tokenized-a'));
$assert(count($venueMappings)===1,'Venue did not expose registered instrument.');
$assert(($venueMappings[0]['instrument']['symbol']??null)==='AAPLx','Venue mapping instrument drifted.');

$assert(($aapl['status']??null)==='ACTIVE','AAPL acceptance instrument is not ACTIVE.');
$assert(($aaplx['family']??null)==='tokenized_security','AAPLx acceptance family drifted.');
$assert(($relationship['strength']??null)==='EXACT','Acceptance relationship strength drifted.');
$assert(($venue['supported_instrument_count']??-1)===0,'Venue should be empty before registration.');
$assert(($mapping['venue']['code']??null)==='TVA','Venue mapping lost venue identity.');

$assert(count($auditSink->records)===5,'Acceptance workflow must emit five audit records.');
$assert(count($events->events)===5,'Acceptance workflow must emit five domain events.');
$assert($transactions->transactions===5,'Each successful Foundation mutation must own one application transaction.');
$assert(($events->correlations[0]??null)==='corr-aapl','Event correlation id must follow the command.');
$assert(($events->actors[0]??null)===42,'Event actor id must follow the command.');
$eventTypes=array_map(static fn(AbstractCapitalMarketsEvent $event):string=>$event->eventName(),$events->events);
foreach([
    'capital_markets.instrument.created.v1',
    'capital_markets.relationship.created.v1',
    'capital_markets.venue.created.v1',
    'capital_markets.venue_instrument.registered.v1',
] as $eventType){
    $assert(in_array($eventType,$eventTypes,true),'Acceptance workflow missing event type: '.$eventType);
}

try{
    $service->createInstrument(new CreateInstrument($org,$actor,'corr-duplicate-id',[
        'id'=>'instrument:aapl',
        'symbol'=>'AAPL2',
        'canonical_symbol'=>'AAPL2',
        'name'=>'Duplicate aggregate',
        'family'=>'equity',
        'status'=>'ACTIVE',
    ]));
    throw new RuntimeException('Duplicate instrument id was accepted.');
}catch(DomainException $error){
    $assert($error->getMessage()==='Instrument already exists.','Unexpected duplicate aggregate error.');
}

try{
    $service->createInstrument(new CreateInstrument($org,$actor,'corr-duplicate-identifier',[
        'id'=>'instrument:duplicate-isin',
        'symbol'=>'DUPL',
        'canonical_symbol'=>'DUPL',
        'name'=>'Duplicate identifier',
        'family'=>'equity',
        'status'=>'ACTIVE',
        'identifiers'=>[
            ['type'=>'ISIN','value'=>'US0378331005'],
        ],
    ]));
    throw new RuntimeException('Duplicate typed identifier was accepted.');
}catch(DomainException $error){
    $assert(str_contains($error->getMessage(),'already belongs to another instrument'),'Unexpected identifier uniqueness error.');
}

$assert($service->getInstrument(new GetInstrument('other-tenant','instrument:aaplx'))===null,'Tenant isolation failed in acceptance repository.');
$assert(count($venues->instruments('other-tenant',VenueId::fromString('venue:tokenized-a')))===0,'Venue mapping leaked across tenant.');

echo "Capital Markets CM-FOUNDATION acceptance slice passed.\n";
