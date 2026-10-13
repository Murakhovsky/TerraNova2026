<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static function(string $path)use($root):string{
    $s=file_get_contents($root.'/'.$path);
    if(!is_string($s)||$s==='')throw new RuntimeException('Missing Capital Markets runtime team artifact: '.$path);
    return $s;
};
$module=$read('app/Domains/CapitalMarkets/Bootstrap/CapitalMarketsDomainModule.php');
$orchestrator=$read('app/Domains/CapitalMarkets/Application/Service/CapitalMarketsRuntimeOrchestrator.php');
$context=$read('app/Domains/CapitalMarkets/Infrastructure/Agent/CapitalMarketsSpecialistContextBuilder.php');
$cli=$read('symfony/src/Command/CapitalMarketsAgentCycleCommand.php');
$di=$read('symfony/config/services.yaml');
$validator=$read('app/Domains/CapitalMarkets/Automation/Agent/CapitalMarketsSpecialistResultValidator.php');
foreach(['CapitalMarketsMarketIntelligenceAgent','CapitalMarketsOpportunityAgent',
    'CapitalMarketsRiskAgent','CapitalMarketsExecutionAgent','CapitalMarketsOrchestratorAgent',
    'CapitalMarketsResearchAgent','CapitalMarketsPortfolioAgent'] as $agent){
    if(!str_contains($module,$agent.'::definition') && !in_array($agent,[
        'CapitalMarketsResearchAgent','CapitalMarketsPortfolioAgent'
    ],true))throw new RuntimeException('Specialist not registered: '.$agent);
    if(!str_contains($module,$agent.'::NAME'))throw new RuntimeException('Agent context mapping missing '.$agent);
}
$pos=-1;
foreach(["'Observe'","'Research'","'Detect'","'Assess'","'Allocate'","'Execute'","'Measure'","'Learn'"] as $stage){
    $i=strpos($orchestrator,$stage,$pos+1);
    if($i===false||$i<=$pos)throw new RuntimeException('Runtime stage ordering is broken: '.$stage);
    $pos=$i;
}
foreach(['gates->observation','gates->opportunity','gates->risk',
    'gates->measuredExecution','getExecutionForOpportunity',
    'AgentRuntime','CapitalMarketsResearchAgentService','CapitalMarketsPortfolioAgentService',
    'PAPER_EXECUTION_PENDING_HUMAN_APPROVAL'] as $check){
    if(!str_contains($orchestrator,$check)&&$check!=='PAPER_EXECUTION_PENDING_HUMAN_APPROVAL'){
        throw new RuntimeException('Orchestrator did not enforce stage authority: '.$check);
    }
}
foreach(['MarketDataAdministrationService','CapitalRiskService','CapitalMarketsTradingRepositoryInterface',
    'prior_stage_evidence','allow_order_execution\'=>false','allow_self_approval\'=>false'] as $check){
    if(!str_contains($context,$check))throw new RuntimeException('Context isolation missing '.$check);
}
foreach(['proposedActions!==[]','Unknown specialist agent identity.'] as $check){
    if(!str_contains($validator,$check))throw new RuntimeException('Specialist authority validator missing '.$check);
}
foreach(['COS_CM_RUNTIME_AGENT_TEAM','cos:capital-markets:agents:cycle',
    'CapitalMarketsRuntimeOrchestrator'] as $check){
    if(!str_contains($cli,$check))throw new RuntimeException('Operator command guard missing '.$check);
}
foreach(['$specialistAgentContext','CapitalMarketsSpecialistContextBuilder:',
    'CapitalMarketsRuntimeEvidenceGate:','CapitalMarketsRuntimeOrchestrator:'] as $check){
    if(!str_contains($di,$check))throw new RuntimeException('Symfony team service wiring missing '.$check);
}
echo "Capital Markets runtime team eight-stage architecture contract passed.\n";
