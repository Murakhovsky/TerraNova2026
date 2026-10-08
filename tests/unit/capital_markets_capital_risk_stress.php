<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Service\PortfolioStressEngine;
use Domains\CapitalMarkets\Domain\Stress\PortfolioStressScenario;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$engine=new PortfolioStressEngine();

$hedged=$engine->run(
    new PortfolioStressScenario('btc-down','BTC -20%',['BTC'=>'-0.20']),
    Decimal::fromString('100000'),
    [
        ['position_id'=>'spot','asset'=>'BTC','venue'=>'A','side'=>'LONG','notional'=>'50000'],
        ['position_id'=>'perp','asset'=>'BTC','venue'=>'B','side'=>'SHORT','notional'=>'50000'],
    ]
);
$assert($hedged->estimatedLoss->isZero(),'Equal long/short BTC hedge must offset price-shock P&L.');
$assert(count($hedged->positionsAffected)===2,'Both hedge legs must remain operationally visible in stress result.');

$venueDown=$engine->run(
    new PortfolioStressScenario('venue-down','Bybit unavailable',['VENUE:BYBIT'=>'OFFLINE']),
    Decimal::fromString('100000'),
    [
        ['position_id'=>'p1','asset'=>'BTC','venue'=>'BYBIT','side'=>'LONG','notional'=>'20000','initial_margin'=>'5000','available_margin'=>'2000'],
    ]
);
$assert($venueDown->estimatedLoss->isZero(),'Venue outage must not be fabricated as 100% market loss.');
$assert($venueDown->marginImpact->value()==='7000','Venue outage must expose unavailable margin capital.');
$assert(in_array('BYBIT',$venueDown->venuesAffected,true),'Venue outage must identify affected venue.');
$assert(in_array('VENUE_OPERATIONAL_UNAVAILABLE:BYBIT',$venueDown->riskLimitsBreached,true),'Venue outage must create operational breach.');

$depeg=$engine->run(
    new PortfolioStressScenario('usdt-depeg','USDT 0.97',['USDT'=>'-0.03']),
    Decimal::fromString('100000'),
    [
        ['position_id'=>'p2','asset'=>'BTC','venue'=>'BYBIT','side'=>'LONG','notional'=>'0','collateral_asset'=>'USDT','collateral_value'=>'20000'],
    ]
);
$assert($depeg->estimatedLoss->value()==='600','USDT -3% on 20k collateral must create 600 economic loss.');
$assert($depeg->marginImpact->value()==='600','Collateral depeg must reduce margin capacity.');

echo "Capital Markets stress hostile scenarios passed.\n";
