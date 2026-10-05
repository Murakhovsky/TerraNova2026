<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditAction;
use Domains\CapitalMarkets\Application\Audit\CapitalMarketsAuditResourceType;
use Domains\CapitalMarkets\Application\Feature\CapitalMarketsFeatureFlag;
use Domains\CapitalMarkets\Domain\Event\EconomicRelationshipDefined;
use Domains\CapitalMarkets\Domain\Event\InstrumentRegistered;
use Domains\CapitalMarkets\Domain\Event\VenueRegistered;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationship;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipGraph;
use Domains\CapitalMarkets\Domain\Instrument\EconomicRelationshipType;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentBasket;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentDescriptor;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentFamily;
use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\Instrument\MarketPair;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Value\QuoteUnit;
use Domains\CapitalMarkets\Domain\Value\Rate;
use Domains\CapitalMarkets\Domain\Venue\VenueDescriptor;
use Domains\CapitalMarkets\Domain\Venue\VenueId;
use Domains\CapitalMarkets\Domain\Venue\VenueType;
use Domains\CapitalMarkets\Model\CapitalMarketsCapability;
use Kernel\Module\ModuleDefinition;
use Kernel\Shared\Domain\Money;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$aapl = new InstrumentDescriptor(
    InstrumentId::fromString('equity:aapl'),
    InstrumentFamily::Equity,
    'AAPL',
    'Apple Inc. common stock',
    ['isin' => 'US0378331005'],
);
$aaplx = new InstrumentDescriptor(
    InstrumentId::fromString('tokenized:aaplx'),
    InstrumentFamily::TokenizedSecurity,
    'AAPLx',
    'Tokenized Apple exposure',
    ['provider_symbol' => 'AAPLx'],
);

$assert($aapl->family === InstrumentFamily::Equity, 'Equity family was not preserved.');
$assert($aaplx->family === InstrumentFamily::TokenizedSecurity, 'Tokenized security family was not preserved.');

$relationship = new EconomicRelationship(
    $aaplx->id,
    $aapl->id,
    EconomicRelationshipType::Represents,
    'issuer:reference-document',
);
$graph = new EconomicRelationshipGraph();
$graph->add($relationship);
$graph->add($relationship);
$assert(count($graph->all()) === 1, 'Economic relationship graph must be idempotent for the same fact.');
$assert(count($graph->between($aapl->id, $aaplx->id)) === 1, 'Economic relationship lookup failed.');

$pair = new MarketPair($aapl->id, $aaplx->id);
$basket = new InstrumentBasket([$aapl->id, $aaplx->id]);
$assert($pair->right->equals($aaplx->id), 'Market pair lost instrument identity.');
$assert(count($basket->instruments) === 2, 'Instrument basket lost members.');

$venue = new VenueDescriptor(
    VenueId::fromString('venue:kraken'),
    VenueType::Cex,
    'Kraken',
);
$assert($venue->type === VenueType::Cex, 'Venue type was not preserved.');

$decimal = Decimal::fromString('+0012.3400');
$assert($decimal->value() === '12.34', 'Decimal normalization is not deterministic.');

$price = new Price(Decimal::fromString('182.1250'), new QuoteUnit('usdt'));
$quantity = new Quantity(Decimal::fromString('0.50000000'), $aaplx->id);
$funding = new Rate(Decimal::fromString('-0.000125'));
$assert($price->quoteUnit->value() === 'USDT', 'Price quote unit normalization failed.');
$assert($quantity->amount->value() === '0.5', 'Quantity normalization failed.');
$assert($funding->ratio->isNegative(), 'Negative rates must be representable.');

try {
    new Quantity(Decimal::fromString('-1'), $aapl->id);
    throw new RuntimeException('Negative quantity was accepted.');
} catch (InvalidArgumentException) {
}

$money = new Money(12500, 'USD');
$assert($money->currency() === 'USD', 'Capital Markets must reuse Kernel Money.');

$assert(in_array('capital_markets.live.execute', CapitalMarketsCapability::values(), true), 'Live execution permission is missing.');
$assert(in_array('capital_markets.live_trading', CapitalMarketsFeatureFlag::values(), true), 'Live trading feature flag is missing.');
$assert(CapitalMarketsAuditAction::RiskDecisionRecorded->value === 'capital_markets.risk.decision_recorded', 'Audit action vocabulary is unstable.');
$assert(CapitalMarketsAuditResourceType::Instrument->value === 'capital_markets.instrument', 'Audit resource vocabulary is unstable.');

$at = new DateTimeImmutable('2026-10-05T18:00:00+00:00');
$instrumentEvent = new InstrumentRegistered('evt-instrument-1', $at, $aapl->id);
$relationshipEvent = new EconomicRelationshipDefined('evt-relationship-1', $at, $aaplx->id, $aapl->id, EconomicRelationshipType::Represents);
$venueEvent = new VenueRegistered('evt-venue-1', $at, $venue->id);
$assert($instrumentEvent->eventName() === InstrumentRegistered::TYPE, 'Instrument event type is unstable.');
$assert($relationshipEvent->eventName() === EconomicRelationshipDefined::TYPE, 'Relationship event type is unstable.');
$assert($venueEvent->eventName() === VenueRegistered::TYPE, 'Venue event type is unstable.');

$manifest = ModuleDefinition::fromArray(require dirname(__DIR__, 2) . '/app/Domains/CapitalMarkets/module.php');
$assert($manifest->manifest->id === 'capital_markets', 'Capital Markets module id is invalid.');
$assert($manifest->manifest->version === '0.1.0', 'Capital Markets foundation version is invalid.');
$assert($manifest->manifest->enabledByDefault === false, 'Capital Markets must be disabled by default.');
$assert($manifest->contributions->runtimeModuleService === null, 'Foundation must not expose runtime execution.');
$assert($manifest->contributions->migrationFiles === [], 'Foundation must not create persistence.');
foreach (CapitalMarketsCapability::values() as $capability) {
    $assert(in_array($capability, $manifest->contributions->capabilities, true), 'Manifest capability missing: ' . $capability);
}

echo "Capital Markets V0.1 domain foundation passed.\n";
