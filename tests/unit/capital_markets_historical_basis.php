<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Service\HistoricalRelationshipBasisProjector;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\CanonicalMarketEvent;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketEventType;
use Domains\CapitalMarkets\Domain\MarketData\MarketQualityFlag;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketValueObservation;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTimestamps;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert = static function(bool $ok,string $message):void {
    if (!$ok) throw new RuntimeException($message);
};
$make = static function(
    string $id, string $instrument, string $time, string $price, string $unit='USD',
    array $quality=[], MarketDataMode $mode=MarketDataMode::Live,
    MarketStatus $status=MarketStatus::Open,
):CanonicalMarketEvent {
    $stamp=new DateTimeImmutable($time);
    $base=new AssetCode($instrument);
    $quote=new AssetCode($unit);
    $bid=new Price(Decimal::fromString($price),$base,$quote,8);
    $ask=new Price(Decimal::fromString($price),$base,$quote,8);
    $observation=new MarketQuote($bid,new Quantity(Decimal::fromString('1'),$base,8),$ask,new Quantity(Decimal::fromString('1'),$base,8),MarketEventType::Bbo);
    return new CanonicalMarketEvent(
        $id,MarketSourceId::fromString('source-test'),VenueId::fromString('venue-test'),
        InstrumentId::fromString($instrument),new MarketTimestamps($stamp,$stamp,$stamp),
        null,$observation,$quality,1,$mode,$status,
    );
};

$source=[$make('source-1','AAPLX','2026-10-08T10:00:00Z','103'),$make('source-2','AAPLX','2026-10-08T11:00:00Z','90')];
$target=[$make('target-1','AAPL','2026-10-08T10:00:10Z','100'),$make('target-2','AAPL','2026-10-08T11:00:20Z','100')];
$meta=['conversion_verified'=>true,'target_units_per_source_unit'=>'1'];
$basis=HistoricalRelationshipBasisProjector::project($source,$target,$meta);
$assert($basis['status']==='OBSERVATIONS_AVAILABLE','Two aligned verified observations should be available.');
$assert(count($basis['rows'])===2,'Each target observation must be used at most once.');
$assert($basis['rows'][0]['basis_bps']==='300','Historical basis must use canonical Decimal bps.');
$assert($basis['rows'][1]['basis_bps']==='-1000','Negative historical basis calculation is wrong.');
$assert($basis['rows'][0]['skew_seconds']===10,'True event timestamp skew is required.');
$assert($basis['scope']==='HISTORICAL_OBSERVATION_ONLY','Observed price dislocation must not become an executable trade.');

$referenceTime=new DateTimeImmutable('2026-10-08T10:00:12Z');
$referenceEvent=new CanonicalMarketEvent(
    'reference-1',MarketSourceId::fromString('reference-feed'),null,
    InstrumentId::fromString('AAPL'),new MarketTimestamps($referenceTime,$referenceTime,$referenceTime),
    null,new MarketValueObservation(MarketEventType::ReferencePrice,Decimal::fromString('100'),new AssetCode('USD')),
    [],1,MarketDataMode::Live,MarketStatus::Open,
);
$referenceBasis=HistoricalRelationshipBasisProjector::project([$source[0]],[$referenceEvent],$meta);
$assert($referenceBasis['status']==='OBSERVATIONS_AVAILABLE'
    && $referenceBasis['rows'][0]['basis_bps']==='300',
    'A trusted canonical reference price with matching unit must support tokenized-equity comparison.');

$noRatio=HistoricalRelationshipBasisProjector::project($source,$target,[]);
$assert($noRatio['status']==='NOT COMPARABLE' && $noRatio['rows']===[],'Never imply a one-to-one economic ratio.');

$currency=HistoricalRelationshipBasisProjector::project($source,[$make('eur-1','AAPL','2026-10-08T10:00:10Z','100','EUR')],$meta);
$assert($currency['rows']===[] && $currency['reason']==='QUOTE_CURRENCY_MISMATCH','Cross-currency quotes must not be compared.');

$stale=HistoricalRelationshipBasisProjector::project([$make('stale','AAPLX','2026-10-08T10:00:00Z','103','USD',[MarketQualityFlag::Stale])],$target,$meta);
$assert($stale['rows']===[],'Stale observations must be rejected.');
$replay=HistoricalRelationshipBasisProjector::project([$make('replay','AAPLX','2026-10-08T10:00:00Z','103','USD',[],MarketDataMode::Replay)],$target,$meta);
$assert($replay['rows']===[],'Replay observations must not masquerade as live history.');
$closed=HistoricalRelationshipBasisProjector::project([$make('halt','AAPLX','2026-10-08T10:00:00Z','103','USD',[],MarketDataMode::Live,MarketStatus::Halted)],$target,$meta);
$assert($closed['rows']===[],'Halted-market observations must not be compared.');
$skew=HistoricalRelationshipBasisProjector::project([$make('later','AAPLX','2026-10-08T10:05:00Z','103')],$target,$meta);
$assert($skew['rows']===[] && $skew['reason']==='TIMESTAMPS_NOT_ALIGNED','Unaligned market timestamps must block synthetic historical spreads.');
$ratio=HistoricalRelationshipBasisProjector::project([$make('ratio','AAPLX','2026-10-08T10:00:00Z','206')],[$target[0]],[
    'conversion_verified'=>true,'target_units_per_source_unit'=>'2',
]);
$assert($ratio['rows'][0]['basis_bps']==='300','Explicit fractional economic equivalence must normalize the reference price.');

echo "Capital Markets historical relationship basis passed.\n";
