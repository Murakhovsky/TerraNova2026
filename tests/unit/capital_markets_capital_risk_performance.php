<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Service\PortfolioPerformanceAttributionEngine;
use Domains\CapitalMarkets\Domain\Service\CapitalVelocityEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$engine=new PortfolioPerformanceAttributionEngine();
$result=$engine->attribute([
    ['pnl'=>'500','deployed_capital'=>'10000','risk_consumed'=>'1000','strategy'=>'A','asset'=>'BTC','venue'=>'BYBIT','opportunity_type'=>'H5','instrument_family'=>'PERPETUAL','risk_bucket'=>'R1'],
    ['pnl'=>'-100','deployed_capital'=>'5000','risk_consumed'=>'500','strategy'=>'B','asset'=>'ETH','venue'=>'OKX','opportunity_type'=>'H4','instrument_family'=>'SPOT','risk_bucket'=>'R2'],
]);

$assert($result['net_pnl']->value()==='400','Portfolio net P&L attribution must sum strategy contributions.');
$assert($result['deployed_capital']->value()==='15000','Portfolio deployed capital attribution must sum absolute capital.');
$assert($result['risk_consumed']->value()==='1500','Risk-consumed attribution must use supplied evidence.');
$assert($result['capital_efficiency']->value()==='0.026666666666','Capital efficiency must be Net P&L / deployed capital.');
$assert($result['risk_efficiency']->value()==='0.266666666666','Risk efficiency must be Net P&L / risk consumed.');
$assert($result['by_strategy']['A']['pnl']->value()==='500','Strategy attribution A is wrong.');
$assert($result['by_venue']['OKX']['pnl']->value()==='-100','Venue attribution OKX is wrong.');

$velocity=new CapitalVelocityEngine();
$rocTime=$velocity->returnOnCapitalTime(Decimal::fromString('100'),Decimal::fromString('10000'),100);
$assert($rocTime->value()==='0.0001','Return on capital-time must not invent annualization.');
$assert($velocity->velocity(Decimal::fromString('10000'),100)->value()==='100','Capital velocity foundation metric is wrong.');

echo "Capital Markets portfolio performance attribution passed.\n";
