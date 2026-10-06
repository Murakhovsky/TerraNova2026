<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\ParameterSensitivityEngine;
use Domains\CapitalMarkets\Domain\Research\ReplayDataGuard;
use Domains\CapitalMarkets\Domain\Research\ResearchIsolationPolicy;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$guard=new ReplayDataGuard();
$simulated=new DateTimeImmutable('2026-01-01T10:00:00Z');

$futureBlocked=false;
try{
    $guard->assertAvailableAt($simulated,new DateTimeImmutable('2026-01-01T10:01:00Z'),'future funding');
}catch(InvalidArgumentException){
    $futureBlocked=true;
}
$assert($futureBlocked,'Replay must reject future data.');

$costsBlocked=false;
try{
    $guard->assertTransactionCosts(['fees'=>['maker'=>'0.001']]);
}catch(InvalidArgumentException){
    $costsBlocked=true;
}
$assert($costsBlocked,'Backtest without slippage must be invalid for promotion.');

$isolation=new ResearchIsolationPolicy();
$isolation->assertPartitions(
    ['from'=>'2025-01-01','to'=>'2025-06-01'],
    ['from'=>'2025-06-01','to'=>'2025-09-01'],
    ['from'=>'2025-09-01','to'=>'2025-12-01'],
);

$overlapBlocked=false;
try{
    $isolation->assertPartitions(
        ['from'=>'2025-01-01','to'=>'2025-07-01'],
        ['from'=>'2025-06-01','to'=>'2025-09-01'],
        ['from'=>'2025-09-01','to'=>'2025-12-01'],
    );
}catch(InvalidArgumentException){
    $overlapBlocked=true;
}
$assert($overlapBlocked,'TRAIN and VALIDATION overlap must be blocked.');

$reuseBlocked=false;
try{
    $isolation->assertNewOosPeriod(
        ['from'=>'2025-09-01','to'=>'2025-12-01'],
        ['from'=>'2025-09-01','to'=>'2025-12-01'],
    );
}catch(InvalidArgumentException){
    $reuseBlocked=true;
}
$assert($reuseBlocked,'Failed OOS period must not be reused after tuning.');

$sensitivity=(new ParameterSensitivityEngine())->analyze([
    ['parameter'=>0.40,'score'=>10],
    ['parameter'=>0.42,'score'=>100],
    ['parameter'=>0.44,'score'=>8],
]);
$assert(($sensitivity['warning']??null)==='OVERFIT_RISK','Narrow parameter peak must raise OVERFIT_RISK.');

echo "Capital Markets Research Lab bias/OOS golden tests passed.\n";
