<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$replay=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/RelativeValueHistoricalReplayService.php');
foreach([
    'implements ResearchReplayAdapterInterface',
    "['H4','H5','H6']",
    'SpotPerpetualMarketStateFactory',
    'RelativeValueEconomicsCalculator',
    'RelativeValueOpportunityEvaluator',
    'assertAvailableAt',
    'assertTransactionCosts',
    'historicalSpotPerp',
    'crossVenueFunding',
] as $needle){
    $assert(str_contains($replay,$needle),'Shared replay contract missing: '.$needle);
}

$economics=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Service/RelativeValueEconomicsCalculator.php');
$assert(str_contains($economics,'public function historicalSpotPerp('),'Historical spot/perp replay must reuse production economics engine.');
$assert(str_contains($economics,'ExpectedEconomics'),'Historical replay must return canonical ExpectedEconomics.');

$backtest=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchBacktestService.php');
foreach([
    'Backtest dataset must equal frozen experiment dataset.',
    'Backtest strategy version must equal experiment strategy version.',
    'Cancelled backtest run cannot start.',
    'walkForward(',
    'assertBacktest',
    'assertWalkForward',
] as $needle){
    $assert(str_contains($backtest,$needle),'Backtest orchestration guard missing: '.$needle);
}

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/api/v1/capital-markets/research/backtests',
    '/api/v1/capital-markets/research/backtests/run',
    '/api/v1/capital-markets/research/backtests/{id}/cancel',
    '/api/v1/capital-markets/research/walk-forward',
] as $route){
    $assert(str_contains($routes,$route),'Research replay API route missing: '.$route);
}

echo "Capital Markets Research Lab replay contracts passed.\n";
