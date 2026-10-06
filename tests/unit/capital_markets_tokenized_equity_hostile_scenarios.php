<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Instrument\InstrumentId;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataMode;
use Domains\CapitalMarkets\Domain\MarketData\MarketDataQualityAssessment;
use Domains\CapitalMarkets\Domain\MarketData\MarketQuote;
use Domains\CapitalMarkets\Domain\MarketData\MarketSourceId;
use Domains\CapitalMarkets\Domain\MarketData\MarketState;
use Domains\CapitalMarkets\Domain\MarketData\MarketStatus;
use Domains\CapitalMarkets\Domain\MarketData\MarketTrustStatus;
use Domains\CapitalMarkets\Domain\Opportunity\SpreadDetectorConfig;
use Domains\CapitalMarkets\Domain\Service\TokenizedEquitySpreadDetector;
use Domains\CapitalMarkets\Domain\Value\AssetCode;
use Domains\CapitalMarkets\Domain\Value\Decimal;
use Domains\CapitalMarkets\Domain\Value\Price;
use Domains\CapitalMarkets\Domain\Value\Quantity;
use Domains\CapitalMarkets\Domain\Venue\VenueId;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$base=new AssetCode('AAPLX');
$quoteAsset=new AssetCode('USD');
$now=new DateTimeImmutable('2026-10-06T12:00:00.500000+00:00');
$config=new SpreadDetectorConfig(
    'hostile-v1',
    Decimal::fromString('10'),
    Decimal::fromString('1'),
    Decimal::fromString('0.01'),
    1000,
    250,
    80,
    Decimal::fromString('50'),
    500,
    Decimal::fromString('0.8'),
);
$detector=new TokenizedEquitySpreadDetector();

$quote=static function(string $bid,string $ask)use($base,$quoteAsset):MarketQuote{
    return new MarketQuote(
        new Price(Decimal::fromString($bid),$base,$quoteAsset,4),
        new Quantity(Decimal::fromString('10'),$base,8),
        new Price(Decimal::fromString($ask),$base,$quoteAsset,4),
        new Quantity(Decimal::fromString('10'),$base,8),
    );
};

$state=static function(
    string $venue,string $bid,string $ask,string $timestamp,
    MarketStatus $status=MarketStatus::Open,
    MarketTrustStatus $trust=MarketTrustStatus::Trusted,
    int $score=100,
)use($quote):MarketState{
    $quality=new MarketDataQualityAssessment($trust,$score,[],50,10,60,null);
    return new MarketState(
        InstrumentId::fromString('instrument:'.$venue),
        VenueId::fromString($venue),
        MarketSourceId::fromString('source:'.$venue),
        null,
        $quote($bid,$ask),
        null,
        null,
        $status,
        new DateTimeImmutable($timestamp),
        new DateTimeImmutable($timestamp),
        $quality,
        1,
        null,
        str_repeat('a',64),
        MarketDataMode::Live,
    );
};

$a=$state('venue:a','100','100.1','2026-10-06T12:00:00.400000+00:00');
$b=$state('venue:b','100.8','100.9','2026-10-06T12:00:00.410000+00:00');
$healthy=$detector->detectCrossVenue('pair:aaplx',$a,$b,$config,$now,1000);
$assert(count($healthy)===1,'Healthy baseline must produce the expected H2 candidate.');

$stale=$state('venue:a','100','100.1','2026-10-06T11:59:58.000000+00:00');
$issues=$detector->crossVenueObservationIssues($stale,$b,$config,$now,1000);
$assert(in_array('A_STALE',$issues,true),'Stale trading state must be rejected explicitly.');
$assert($detector->detectCrossVenue('pair:aaplx',$stale,$b,$config,$now,1000)===[],
    'Stale state must never produce a candidate.');

$closed=$state('venue:b','100.8','100.9','2026-10-06T12:00:00.410000+00:00',MarketStatus::Closed);
$issues=$detector->crossVenueObservationIssues($a,$closed,$config,$now,1000);
$assert(in_array('B_MARKET_NOT_OPEN',$issues,true),'Closed market must fail closed.');
$assert($detector->detectCrossVenue('pair:aaplx',$a,$closed,$config,$now,1000)===[],
    'Closed venue must never produce an executable candidate.');

$untrusted=$state(
    'venue:b','100.8','100.9','2026-10-06T12:00:00.410000+00:00',
    MarketStatus::Open,MarketTrustStatus::Untrusted,40
);
$issues=$detector->crossVenueObservationIssues($a,$untrusted,$config,$now,1000);
$assert(in_array('B_UNTRUSTED',$issues,true),'Provider disagreement/untrusted quality must be rejected.');
$assert($detector->detectCrossVenue('pair:aaplx',$a,$untrusted,$config,$now,1000)===[],
    'Untrusted provider state must never produce a candidate.');

$skewed=$state('venue:b','100.8','100.9','2026-10-06T11:59:59.900000+00:00');
$issues=$detector->crossVenueObservationIssues($a,$skewed,$config,$now,1000);
$assert(in_array('SNAPSHOT_SKEW_EXCEEDED',$issues,true),'Cross-venue timestamp skew must fail closed.');

$repriced=$state('venue:b','100.05','100.15','2026-10-06T12:00:00.420000+00:00');
$afterLatency=$detector->detectCrossVenue('pair:aaplx',$a,$repriced,$config,$now,1000);
$assert($afterLatency===[],
    'A spread that disappears before revalidation must not survive as an executable candidate.');

echo "Capital Markets VS1 hostile market scenarios passed.\n";
