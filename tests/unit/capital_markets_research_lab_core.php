<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);

$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$requiredFiles=[
    'app/Domains/CapitalMarkets/Domain/Research/ResearchHypothesis.php',
    'app/Domains/CapitalMarkets/Domain/Research/ResearchDataset.php',
    'app/Domains/CapitalMarkets/Domain/Research/ResearchExperiment.php',
    'app/Domains/CapitalMarkets/Domain/Research/ResearchResult.php',
    'app/Domains/CapitalMarkets/Domain/Research/StrategyVersion.php',
    'app/Domains/CapitalMarkets/Domain/Research/StrategyScorecard.php',
    'app/Domains/CapitalMarkets/Domain/Research/StrategyPromotionGate.php',
    'app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php',
    'app/Domains/CapitalMarkets/Infrastructure/Persistence/MySql/MysqlResearchLabRepository.php',
    'app/migrations/20261006_000133_capital_markets_research_lab.sql',
];
foreach($requiredFiles as $file){
    $assert(is_file($root.'/'.$file),'Missing CM Research Lab file: '.$file);
}

$hypothesis=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Research/ResearchHypothesis.php');
foreach(['economicReason','edgeSource','UNKNOWN edge source is allowed only for IDEA/DRAFT'] as $needle){
    $assert(str_contains($hypothesis,$needle),'Hypothesis governance missing: '.$needle);
}

$dataset=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Research/ResearchDataset.php');
foreach(['snapshotHash','DatasetQualityStatus','canRunExperiment'] as $needle){
    $assert(str_contains($dataset,$needle),'Dataset freeze/quality contract missing: '.$needle);
}

$service=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Application/Service/ResearchLabService.php');
foreach([
    'Experiment requires a frozen dataset.',
    'Completed experiment result is immutable.',
    "hash('sha256'",
    'evaluatePromotion(',
] as $needle){
    $assert(str_contains($service,$needle),'Research Lab application guard missing: '.$needle);
}

$promotion=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Domain/Research/StrategyPromotionGate.php');
foreach([
    "'RESEARCH:BACKTEST'",
    "'BACKTEST:OOS'",
    "'OOS:PAPER'",
    "'PAPER:LIMITED_LIVE'",
    "'MANUAL_REVIEW_REQUIRED'",
] as $needle){
    $assert(str_contains($promotion,$needle),'Promotion gate contract missing: '.$needle);
}

$migration=(string)file_get_contents($root.'/app/migrations/20261006_000133_capital_markets_research_lab.sql');
foreach([
    'tn_capital_market_research_hypotheses',
    'tn_capital_market_research_datasets',
    'tn_capital_market_strategy_versions',
    'tn_capital_market_research_experiments',
    'tn_capital_market_research_results',
    'tn_capital_market_strategy_promotion_decisions',
    'uq_cm_research_result_experiment',
] as $needle){
    $assert(str_contains($migration,$needle),'Research Lab persistence contract missing: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach(['ResearchLabRepositoryInterface','MysqlResearchLabRepository','ResearchLabService','StrategyPromotionGate'] as $needle){
    $assert(str_contains($services,$needle),'Research Lab service wiring missing: '.$needle);
}

echo "Capital Markets Research Lab core contracts passed.\n";
