<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$assert=static function(bool $condition,string $message):void{
    if(!$condition)throw new RuntimeException($message);
};

$agent=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Automation/Agent/CapitalMarketsResearchAgent.php');
foreach([
    "capital_markets_research",
    "maxActionsPerRun:0",
    "configurationManaged:false",
    "cannot validate a strategy",
    "AI proposes; deterministic engines test; data decides",
] as $needle){
    $assert(str_contains($agent,$needle),'Research Agent authority contract missing: '.$needle);
}

$context=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Infrastructure/Research/ResearchAgentContextBuilder.php');
foreach([
    "listHypotheses",
    "listExperiments",
    "listKnowledge",
    "listFundingObservations",
    "live_trading_authority'=>false",
    "risk_limit_authority'=>false",
] as $needle){
    $assert(str_contains($context,$needle),'Research Agent context boundary missing: '.$needle);
}

$module=(string)file_get_contents($root.'/app/Domains/CapitalMarkets/Bootstrap/CapitalMarketsDomainModule.php');
foreach(['AgentProvidingModuleInterface','CapitalMarketsResearchAgent::definition','agentContextBuilders'] as $needle){
    $assert(str_contains($module,$needle),'Capital Markets agent registration missing: '.$needle);
}

$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
foreach([
    'ResearchSearchHypothesesTool',
    'ResearchCreateHypothesisDraftTool',
    'ResearchCreateExperimentDraftTool',
    'ResearchSearchKnowledgeTool',
    'CompositeToolPermissionChecker',
    'ResearchAgentToolPermissionChecker',
] as $needle){
    $assert(str_contains($services,$needle),'Research tool runtime wiring missing: '.$needle);
}

$permission=(string)file_get_contents($root.'/symfony/src/Infrastructure/Automation/ResearchAgentToolPermissionChecker.php');
$assert(!str_contains($permission,'promote'),'Research Agent tool permission boundary must not expose promotion.');
$assert(!str_contains($permission,'live'),'Research Agent tool permission boundary must not expose live execution.');

$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
foreach([
    '/capital-markets/research',
    '/api/v1/capital-markets/research/hypotheses',
    '/api/v1/capital-markets/research/datasets',
    '/api/v1/capital-markets/research/experiments',
    '/api/v1/capital-markets/strategies/{id}/promotion-request',
] as $route){
    $assert(str_contains($routes,$route),'Research Lab route missing: '.$route);
}

echo "Capital Markets Research Agent contracts passed.\n";
