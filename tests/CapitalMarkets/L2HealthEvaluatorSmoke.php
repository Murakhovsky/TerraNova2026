<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/app/Domains/CapitalMarkets/Infrastructure/MarketData/L2/L2HealthEvaluator.php';
use Domains\CapitalMarkets\Infrastructure\MarketData\L2\L2HealthEvaluator;
$e = new L2HealthEvaluator(30);
$m = ['last_publication_at'=>100];
foreach ([
    ['TRUSTED',120,'HEALTHY',true,false],
    ['TRUSTED',131,'STALE',false,true],
    ['CONNECTING',120,'RECOVERING',false,false],
    ['SYNCING',120,'RECOVERING',false,false],
    ['BACKOFF',120,'DEGRADED',false,true],
    ['STOPPED',120,'STOPPED',false,false],
] as [$state,$now,$expected,$trusted,$alert]) {
    $result=$e->evaluate(['state'=>$state,'error'=>null],$m,$now);
    if ($result['status']!==$expected || $result['trusted']!==$trusted || $result['alert']!==$alert) {
        throw new RuntimeException('L2 health status regression: '.$state);
    }
}
if ($e->evaluate(['state'=>'TRUSTED'],['last_publication_at'=>null],120)['status']!=='STALE') {
    throw new RuntimeException('Unknown publication marked healthy.');
}
echo "L2 health evaluator smoke passed\n";
