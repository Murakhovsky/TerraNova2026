<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Domain\Risk\LiquidityBudget;
use Domains\CapitalMarkets\Domain\Service\CapitalStateEngine;
use Domains\CapitalMarkets\Domain\Service\DrawdownEngine;
use Domains\CapitalMarkets\Domain\Service\LiquidityCapacityEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};

$capital=(new CapitalStateEngine())->snapshot(
 'paper-master',Decimal::fromString('100000'),Decimal::fromString('30000'),
 Decimal::fromString('10000'),Decimal::fromString('20000'),Decimal::fromString('5000'),
 Decimal::fromString('5000'),Decimal::fromString('3000'),Decimal::fromString('10000'),
 Decimal::fromString('5000'),Decimal::fromString('2000')
);
$assert($capital->available->value()==='40000','Capital buffers/states must leave 40000 available.');
$assert($capital->total->value()==='100000','Capital total must remain immutable input.');

$liq=(new LiquidityCapacityEngine())->assess(
 Decimal::fromString('10000'),Decimal::fromString('20000'),Decimal::fromString('3000'),
 new LiquidityBudget(Decimal::fromString('25000'),Decimal::fromString('1000'),900),600
);
$assert($liq['can_enter']===true,'Entry should fit visible depth.');
$assert($liq['can_exit_stress']===false,'Stress exit must fail when stressed depth is insufficient.');
$assert($liq['bucket']->value==='L2','Ten-minute exit must be L2.');

$drawdown=(new DrawdownEngine())->drawdown(Decimal::fromString('80000'),Decimal::fromString('100000'));
$assert($drawdown->value()==='0.2','Drawdown should be 20%.');
$state=(new DrawdownEngine())->state($drawdown,['CAUTION'=>'0.05','REDUCED_RISK'=>'0.1','STOP_NEW_RISK'=>'0.15','EMERGENCY'=>'0.3']);
$assert($state->value==='STOP_NEW_RISK','20% drawdown must stop new risk under configured thresholds.');

echo "Capital Markets financial control golden tests passed.\n";
