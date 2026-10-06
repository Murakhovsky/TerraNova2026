<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Domain\Event\InstrumentCreated;
use Domains\CapitalMarkets\Domain\Event\RelationshipCreated;
use Domains\CapitalMarkets\Domain\Event\VenueCreated;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipGraph;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStatus;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipStrength;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentBasket;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifier;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentIdentifierType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentStatus;
use Domains\CapitalMarkets\Domain\Instrument\MarketPair;
use Domains\CapitalMarkets\Domain\Instrument\MarketPairPurpose;
use Domains\CapitalMarkets\Domain\Instrument\RelationshipId;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Currency;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Percentage;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Value\Rate;
use Domains\CapitalMarkets\Domain\Value\RateKind;
use Domains\CapitalMarkets\Domain\Venue\VenueCapability;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrument;
use Domains\CapitalMarkets\Domain\Venue\VenueInstrumentStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueStatus;
use Domains\CapitalMarkets\Domain\Venue\VenueType;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\Money;
use Platform\FeatureFlag\Model\FeatureFlagKey;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$at=new DateTimeImmutable('2026-10-05T18:00:00+00:00');
$aapl=new InstrumentDescriptor(
    InstrumentId::fromString('equity:aapl'),
    'AAPL',
    'AAPL',
    'Apple Inc.',
    InstrumentFamily::Equity,
    InstrumentStatus::Active,
    new Currency('USD'),
    new AssetCode('USD'),
    'issuer:apple',
    'US',
    'venue:nasdaq',
    ['sector'=>'technology'],
    $at,
    $at,
);
$aaplx=new InstrumentDescriptor(
    InstrumentId::fromString('tokenized:aaplx'),
    'AAPLx',
    'AAPLX',
    'Tokenized Apple exposure',
    InstrumentFamily::TokenizedSecurity,
    InstrumentStatus::Active,
    new Currency('USD'),
    new AssetCode('USDT'),
    'issuer:tokenized-provider',
    'EU',
    null,
    ['provider_symbol'=>'AAPLx'],
    $at,
    $at,
);

$assert(count(InstrumentFamily::cases())===15,'Master instrument-family coverage drifted.');
$assert(count(EconomicRelationshipType::cases())===11,'Economic relationship vocabulary drifted.');
$assert(count(VenueType::cases())===9,'Venue type vocabulary drifted.');
$assert(count(VenueCapability::cases())===10,'Venue capability vocabulary drifted.');
$assert(count(InstrumentIdentifierType::cases())===7,'Instrument identifier vocabulary drifted.');
$assert($aapl->isLiveEligible(),'ACTIVE instrument should be structurally eligible for future execution.');
$assert(!(new InstrumentDescriptor(
    InstrumentId::fromString('equity:draft'),'DRAFT','DRAFT','Draft instrument',InstrumentFamily::Equity,
    InstrumentStatus::Draft,null,null,null,null,null,[],$at,$at
))->isLiveEligible(),'DRAFT instrument cannot be live eligible.');

$isin=new InstrumentIdentifier(InstrumentIdentifierType::Isin,'US0378331005');
$assert($isin->uniquenessKey()==='ISIN||US0378331005','Typed identifier uniqueness key drifted.');

$relationship=new EconomicRelationship(
    RelationshipId::fromString('rel:aaplx:aapl'),
    $aaplx->id,
    $aapl->id,
    EconomicRelationshipType::Represents,
    EconomicRelationshipStrength::Exact,
    $at,
    null,
    EconomicRelationshipStatus::Active,
    ['source'=>'issuer-document'],
);
$reverse=new EconomicRelationship(
    RelationshipId::fromString('rel:aapl:aaplx'),
    $aapl->id,
    $aaplx->id,
    EconomicRelationshipType::References,
    EconomicRelationshipStrength::Direct,
    $at,
    null,
    EconomicRelationshipStatus::Active,
);

$graph=new EconomicRelationshipGraph();
$graph->add($relationship);
$graph->add($relationship);
$graph->add($reverse);
$assert(count($graph->all())===2,'Economic relationship graph must be idempotent for the same fact.');
$assert(count($graph->outgoing($aaplx->id))===1,'Directed outgoing lookup failed.');
$assert(count($graph->incoming($aapl->id))===1,'Directed incoming lookup failed.');
$assert(count($graph->between($aapl->id,$aaplx->id))===2,'Bidirectional pair lookup failed.');
$assert(count($graph->traverse($aaplx->id,5))===1,'Cycle-safe traversal must not revisit the start node.');

$pair=new MarketPair('pair:aapl:aaplx',$aapl->id,$aaplx->id,$relationship->id,MarketPairPurpose::Arbitrage);
$basket=new InstrumentBasket([$aapl->id,$aaplx->id]);
$assert($pair->instrumentB->equals($aaplx->id),'Market pair lost instrument identity.');
$assert(count($basket->instruments())===2,'Instrument basket lost members.');

$venue=new VenueDescriptor(
    VenueId::fromString('venue:tokenized-a'),
    'Tokenized Venue A',
    'TVA',
    VenueType::TokenizedSecuritiesVenue,
    VenueStatus::Active,
    'EU',
    'UTC',
    'integration:venue-a',
    ['custody'=>'omnibus'],
);
$mapping=new VenueInstrument(
    $venue->id,
    $aaplx->id,
    'AAPLx',
    VenueInstrumentStatus::Active,
    4,
    8,
    Decimal::fromString('0.001'),
    Decimal::fromString('10'),
);
$assert($venue->type===VenueType::TokenizedSecuritiesVenue,'Venue type was not preserved.');
$assert($mapping->key()==='venue:tokenized-a|tokenized:aaplx','Venue/instrument mapping key drifted.');

$decimal=Decimal::fromString('+0012.3400');
$assert($decimal->value()==='12.34','Decimal normalization is not deterministic.');
$assert(Decimal::fromString('100000000000000000000')->compareTo(Decimal::fromString('99999999999999999999'))>0,'Decimal compare must not use lossy numeric coercion.');

$price=new Price(Decimal::fromString('182.1250'),new AssetCode('AAPL'),new AssetCode('USDT'),4);
$quantity=new Quantity(Decimal::fromString('0.50000000'),new AssetCode('AAPLX'),8);
$funding=new Rate(Decimal::fromString('-0.000125'),RateKind::Funding);
$percentage=new Percentage(Decimal::fromString('0.08'));
$assert($price->toArray()['quote_asset']==='USDT','Price quote asset normalization failed.');
$assert($quantity->toArray()['value']==='0.5','Quantity normalization failed.');
$assert($funding->ratio->isNegative(),'Negative rates must be representable.');
$assert($percentage->toArray()['representation']==='decimal_fraction','Percentage representation must be explicit.');

try{
    new Quantity(Decimal::fromString('-1'),new AssetCode('AAPL'),8);
    throw new RuntimeException('Negative quantity was accepted.');
}catch(InvalidArgumentException){}

$money=new Money(12500,'USD');
$assert($money->currency()==='USD','Capital Markets must reuse Kernel Money.');
$assert($money->compareTo(new Money(12499,'USD'))>0,'Money compareTo failed.');
$assert($money->toArray()['minor_units']===12500,'Money serialization failed.');

foreach(CapitalMarketsFeatureFlag::cases() as $flag){
    $key=new FeatureFlagKey($flag->value);
    $assert($key->value===$flag->value,'Capital Markets feature flag is invalid for Platform runtime: '.$flag->value);
}
$assert(in_array('capital_markets.instrument.manage',CapitalMarketsCapability::values(),true),'Instrument manage capability is missing.');
$assert(!in_array('capital_markets.live.execute',CapitalMarketsCapability::values(),true),'Foundation must not expose live execution permission.');
$assert(CapitalMarketsAuditAction::InstrumentCreated->value==='capital_markets.instrument.created','Audit action vocabulary is unstable.');
$assert(CapitalMarketsAuditResourceType::Instrument->value==='capital_markets.instrument','Audit resource vocabulary is unstable.');

$instrumentEvent=new InstrumentCreated('evt-instrument-1',$at,'tenant-a',$aapl->id,['symbol'=>'AAPL']);
$relationshipEvent=new RelationshipCreated('evt-relationship-1',$at,'tenant-a',$relationship->id,['type'=>'REPRESENTS']);
$venueEvent=new VenueCreated('evt-venue-1',$at,'tenant-a',$venue->id,['code'=>'TVA']);
$assert($instrumentEvent->eventName()===InstrumentCreated::TYPE,'Instrument event type is unstable.');
$assert($relationshipEvent->eventName()===RelationshipCreated::TYPE,'Relationship event type is unstable.');
$assert($venueEvent->eventName()===VenueCreated::TYPE,'Venue event type is unstable.');
$assert($instrumentEvent->envelope()['tenant']==='tenant-a','Event envelope lost tenant.');
$assert($instrumentEvent->envelope()['schema_version']===1,'Event schema version drifted.');

$manifest=ModuleDefinition::fromArray(require dirname(__DIR__,2).'/app/Domains/CapitalMarkets/module.php');
$assert($manifest->manifest->id==='capital_markets','Capital Markets module id is invalid.');
$assert($manifest->manifest->version==='0.5.0','Capital Markets module version is invalid.');
$assert($manifest->manifest->enabledByDefault===false,'Capital Markets must be disabled by default.');
$assert($manifest->contributions->runtimeModuleService==='capitalMarketsDomainModule','Foundation runtime module service is missing.');
foreach([
    'app/migrations/20261005_000124_capital_markets_foundation.sql',
    'app/migrations/20261006_000125_capital_markets_market_sources.sql',
    'app/migrations/20261006_000126_capital_markets_market_events.sql',
    'app/migrations/20261006_000127_capital_markets_market_state.sql',
    'app/migrations/20261006_000128_capital_markets_tokenized_equity_vertical_slice.sql',
    'app/migrations/20261006_000129_capital_markets_tokenized_equity_research.sql',
] as $migration){
    $assert(in_array($migration,$manifest->contributions->migrationFiles,true),'Capital Markets migration missing: '.$migration);
}
foreach(CapitalMarketsCapability::values() as $capability){
    $assert(in_array($capability,$manifest->contributions->capabilities,true),'Manifest capability missing: '.$capability);
}

echo "Capital Markets CM-FOUNDATION domain foundation passed.\n";
