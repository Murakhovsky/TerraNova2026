<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/vendor/autoload.php';

use Domains\CapitalMarkets\Domain\Research\ResearchPaperRun;
use Domains\CapitalMarkets\Domain\Research\StrategyDemotionPolicy;

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$paper=new ResearchPaperRun(
    'paper-1','exp-paper','strategy-v3',['execution-1'],'RUNNING',
    new DateTimeImmutable('2026-01-01T00:00:00Z')
);
$assert($paper->status==='RUNNING','ResearchPaperRun must preserve lifecycle state.');

$demotion=(new StrategyDemotionPolicy())->evaluate(
    ['max_drawdown_ratio'=>'0.18'],
    ['policy_version'=>'risk-v1','max_drawdown_ratio'=>['max'=>'0.10'],'action'=>'RETURN_TO_PAPER']
);
$assert($demotion['action']==='RETURN_TO_PAPER','Demotion must trigger configured deterministic action.');
$assert(in_array('max_drawdown_ratio_ABOVE_MAX',$demotion['reasons'],true),'Demotion reason must preserve failed criterion.');

$missing=(new StrategyDemotionPolicy())->evaluate([],['policy_version'=>'risk-v1']);
$assert($missing['action']==='RESEARCH_REQUIRED','Empty demotion criteria must fail closed.');

$root=dirname(__DIR__,2);
$paperService=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchPaperRunService.php');
foreach([
    'PAPER_FORWARD_TEST',
    'PASSED OOS -> PAPER promotion gate',
    'Unknown paper execution:',
    'Paper completion requires immutable result_id.',
    'getResultForExperiment',
] as $needle){
    $assert(str_contains($paperService,$needle),'Paper governance contract missing: '.$needle);
}

$lab=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php');
foreach([
    'RESEARCH -> BACKTEST requires an experiment',
    'BACKTEST -> OOS requires a completed backtest run.',
    'OOS -> PAPER requires a completed OOS run with result.',
    'PAPER -> LIMITED_LIVE requires a completed paper run.',
    'Promotion policy_version is required.',
    'evaluateDemotion(',
] as $needle){
    $assert(str_contains($lab,$needle),'Promotion/demotion lineage contract missing: '.$needle);
}

$event=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Event/ResearchLifecycleEvent.php');
foreach([
    'hypothesis_created','experiment_started','backtest_completed','oos_completed','paper_completed',
    'strategy_version_created','strategy_scorecard_updated','strategy_promotion_requested',
    'strategy_promoted','strategy_demoted','strategy_rejected'
] as $needle){
    $assert(str_contains($event,$needle),'Research lifecycle event missing: '.$needle);
}

$migration=(string)file_get_contents($root.'/app/migrations/20261006_000133_capital_markets_research_lab.sql');
$ownership=(string)file_get_contents($root.'/app/Infrastructure/Platform/Persistence/TableOwnership.php');
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$assert(str_contains($migration,'tn_capital_market_paper_runs'),'Research Paper Run persistence table missing.');
$assert(str_contains($ownership,'tn_capital_market_paper_runs'),'Research Paper Run table ownership missing.');
foreach([
    '/api/v1/capital-markets/research/paper-runs',
    '/api/v1/capital-markets/strategies/{id}/demotion-request',
] as $route)$assert(str_contains($routes,$route),'Research governance route missing: '.$route);

echo "Capital Markets Research Paper/governance contracts passed.\n";
