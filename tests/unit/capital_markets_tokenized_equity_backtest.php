<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Domain\Research\HistoricalBacktestPromotionPolicy;
use Domains\CapitalMarkets\Domain\Value\Decimal;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$policy=new HistoricalBacktestPromotionPolicy();

$assert(
    $policy->decide(29,100,Decimal::fromString('1'),Decimal::fromString('1'),Decimal::fromString('1'),30)==='INSUFFICIENT_OOS_SAMPLE',
    'Train sample gate drifted.'
);
$assert(
    $policy->decide(100,29,Decimal::fromString('1'),Decimal::fromString('1'),Decimal::fromString('1'),30)==='INSUFFICIENT_OOS_SAMPLE',
    'OOS sample gate drifted.'
);
$assert(
    $policy->decide(100,100,Decimal::fromString('-0.1'),Decimal::fromString('1'),Decimal::fromString('1'),30)==='TRAIN_FAIL',
    'Negative train economics must fail.'
);
$assert(
    $policy->decide(100,100,Decimal::fromString('1'),Decimal::fromString('-0.1'),Decimal::fromString('1'),30)==='OOS_FAIL',
    'Negative OOS economics must fail.'
);
$assert(
    $policy->decide(100,100,Decimal::fromString('1'),Decimal::fromString('1'),Decimal::fromString('0.09'),30)==='OOS_NOT_EXECUTABLE',
    'Low OOS executable ratio must fail.'
);
$assert(
    $policy->decide(100,100,Decimal::fromString('1'),Decimal::fromString('0.25'),Decimal::fromString('0.4'),30)==='OOS_PASS',
    'Positive sufficiently executable OOS evidence must pass.'
);

echo "Capital Markets Tokenized Equity historical backtest promotion policy passed.\n";
