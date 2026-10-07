<?php
declare(strict_types=1);
use Domains\CapitalMarkets\Domain\Allocation\AllocationPolicy;
use Domains\CapitalMarkets\Domain\Portfolio\PortfolioRiskState;
use Domains\CapitalMarkets\Domain\Service\CapitalAllocationEngine;
use Domains\CapitalMarkets\Domain\Service\EconomicExposureEngine;
use Domains\CapitalMarkets\Domain\Value\Decimal;
require dirname(__DIR__,2).'/vendor/autoload.php';
$assert=static function(bool $c,string $m):void{if(!$c)throw new RuntimeException($m);};
$exposure=(new EconomicExposureEngine())->snapshot('paper-master',[
 ['instrument_id'=>'AAPL','underlying_key'=>'AAPL','notional'=>'10000','side'=>'LONG','relationship_valid'=>true],
 ['instrument_id'=>'AAPLx','underlying_key'=>'AAPL','notional'=>'5000','side'=>'LONG','relationship_valid'=>true],
 ['instrument_id'=>'AAPL-PERP','underlying_key'=>'AAPL','notional'=>'12000','side'=>'SHORT','relationship_valid'=>true],
]);
$assert($exposure->grossExposure->value()==='27000','Golden exposure gross must be 27000.');
$assert($exposure->netExposure->value()==='3000','Golden exposure net must be +3000.');
$btc=(new EconomicExposureEngine())->snapshot('paper-master',[
 ['instrument_id'=>'BTC','underlying_key'=>'BTC','notional'=>'60000','side'=>'LONG','relationship_valid'=>true],
 ['instrument_id'=>'BTC-PERP','underlying_key'=>'BTC','notional'=>'60000','side'=>'SHORT','relationship_valid'=>true],
]);
$assert($btc->netExposure->isZero(),'Perfect validated hedge must net to zero.');
$assert(!$btc->grossExposure->isZero(),'Perfect hedge must retain gross exposure.');
$unknown=(new EconomicExposureEngine())->snapshot('paper-master',[
 ['instrument_id'=>'SYNTH-X','underlying_key'=>'AAPL','notional'=>'5000','side'=>'LONG','relationship_valid'=>false],
]);
$assert(count($unknown->unknownExposure)===1,'Invalid relationship must become UNKNOWN_EXPOSURE.');
$assert($unknown->netExposure->isZero(),'Unknown relationship must not be netted.');
$policy=new AllocationPolicy('p','v1','SCORE_BASED','BALANCED',['score_multiplier'=>1],[]);
$engine=new CapitalAllocationEngine();
$opps=[
 ['opportunity_id'=>'A','strategy_version_id'=>'S1','requested_capital'=>'10000','expected_net_return'=>'0.01','confidence'=>0.9,'execution_probability'=>0.9,'capacity'=>'10000','risk'=>1,'concentration_penalty'=>0,'liquidity_penalty'=>0,'strategy_score'=>90],
 ['opportunity_id'=>'B','strategy_version_id'=>'S2','requested_capital'=>'10000','expected_net_return'=>'0.008','confidence'=>0.8,'execution_probability'=>0.9,'capacity'=>'10000','risk'=>1,'concentration_penalty'=>0,'liquidity_penalty'=>0,'strategy_score'=>80],
];
$plan=$engine->allocate('paper-master',Decimal::fromString('12000'),$opps,$policy,PortfolioRiskState::Normal,['A'=>'3000']);
$by=[];foreach($plan->allocations as $i)$by[$i->opportunityId]=$i;
$assert($by['A']->approvedCapital->value()==='3000','Hard headroom must reduce A to 3000.');
$assert($by['A']->decision==='ACCEPT_REDUCED_SIZE','Reduced A must carry reduced-size decision.');
$total=0.0;foreach($plan->allocations as $i)$total+=(float)$i->approvedCapital->value();
$assert($total<=12000.0,'Allocator must never oversubscribe available capital.');
$blocked=$engine->allocate('paper-master',Decimal::fromString('50000'),$opps,$policy,PortfolioRiskState::ReduceOnly);
foreach($blocked->allocations as $i)$assert($i->approvedCapital->isZero()&&$i->decision==='REJECT','REDUCE_ONLY must reject new risk.');
echo "Capital Markets Capital Risk golden financial tests passed.\n";
