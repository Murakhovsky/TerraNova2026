<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\TokenizedEquityHistoricalReplayService;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Service\NetEconomicsEngine;
use Domains\CapitalMarkets\Application\Contract\MarketSnapshotRepositoryInterface;
use Domains\CapitalMarkets\Domain\MarketData\MarketSnapshot;
use Domains\CapitalMarkets\Domain\Contract\RelationshipRepository;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSession;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceMarketState;
use Domains\CapitalMarkets\Domain\MarketData\ReferenceType;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);};

$repo=new class implements MarketSnapshotRepositoryInterface{
    public ?MarketSnapshot $snapshot=null;
    public function save(string $organizationId,MarketSnapshot $snapshot):void{$this->snapshot=$snapshot;}
    public function get(string $organizationId,string $snapshotId):?MarketSnapshot{return $this->snapshot?->snapshotId===$snapshotId?$this->snapshot:null;}
    public function list(string $organizationId,int $limit=500):array{return $this->snapshot===null?[]:[$this->snapshot];}
};
$eq=new AssetCode('AAPL');
$tok=new AssetCode('AAPLX');
$usd=new AssetCode('USD');
$quote=static fn(string $bid,string $ask,AssetCode $base)=>new MarketQuote(
    new Price(Decimal::fromString($bid),$base,$usd,4),new Quantity(Decimal::fromString('20'),$base,8),
    new Price(Decimal::fromString($ask),$base,$usd,4),new Quantity(Decimal::fromString('20'),$base,8),
);
$quality=new MarketDataQualityAssessment(MarketTrustStatus::Trusted,100,[],50,10,60,null);
$ts=new DateTimeImmutable('2026-10-06T10:00:00+00:00');
$reference=new ReferenceMarketState(
    InstrumentId::fromString('instrument:aapl'),MarketSourceId::fromString('source:equity'),
    $quote('100','100.1',$eq),MarketSession::Regular,null,null,ReferenceType::Nbbo,100,$quality,$ts,1,
    MarketDataMode::Replay,$ts,null,str_repeat('b',64),
);
$token=new MarketState(
    InstrumentId::fromString('instrument:aaplx'),VenueId::fromString('venue:token'),
    MarketSourceId::fromString('source:token'),null,$quote('101','101.1',$tok),null,null,
    MarketStatus::Open,$ts,$ts,$quality,1,null,str_repeat('c',64),MarketDataMode::Replay,
);
$repo->snapshot=new MarketSnapshot('snapshot-1',$ts,[$token],[$reference],['source:equity'=>1,'source:token'=>1]);
$relationship=new EconomicRelationship(
    RelationshipId::fromString('relationship:aapl-aaplx'),
    InstrumentId::fromString('instrument:aapl'),
    InstrumentId::fromString('instrument:aaplx'),
    EconomicRelationshipType::Represents,
    EconomicRelationshipStrength::Direct,
    new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
    null,
    EconomicRelationshipStatus::Active,
    ['economic_equivalence_score'=>'0.95']
);
$relationships=new class($relationship) implements RelationshipRepository{
    public function __construct(private EconomicRelationship $relationship){}
    public function save(string $organizationId,EconomicRelationship $relationship):void{$this->relationship=$relationship;}
    public function get(string $organizationId,RelationshipId $id):?EconomicRelationship{return $id->value()===$this->relationship->id->value()?$this->relationship:null;}
    public function forInstrument(string $organizationId,InstrumentId $instrumentId):array{
        return $instrumentId->equals($this->relationship->sourceInstrument)||$instrumentId->equals($this->relationship->targetInstrument)
            ?[$this->relationship]:[];
    }
    public function list(string $organizationId,int $limit=200):array{return [$this->relationship];}
};
$service=new TokenizedEquityHistoricalReplayService($repo,new TokenizedEquitySpreadDetector(),new NetEconomicsEngine(),$relationships);
$options=['buy_fee_rate'=>'0.001','sell_fee_rate'=>'0.001'];
$a=$service->replay('org',$options);
$b=$service->replay('org',$options);
$assert($a['dataset_hash']===$b['dataset_hash'],'Historical replay dataset hash must be deterministic.');
$assert($a['mutated']===false,'Historical replay must be read-only.');
$assert($a['snapshot_count']===1,'Historical replay snapshot count drifted.');
$assert($a['observation_count']>=1,'Relationship-backed H1 replay must create observations.');
$assert(count(array_filter($a['observations'],static fn(array $row):bool=>($row['hypothesis']??null)==='H1'))>=1,'H1 replay evidence is missing.');

echo "Capital Markets Tokenized Equity universe/replay passed.\n";
