<?php
declare(strict_types=1);

use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsMarketIntelligenceAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOpportunityAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsRiskAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsExecutionAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsOrchestratorAgent;
use Domains\CapitalMarkets\Automation\Agent\CapitalMarketsSpecialistResultValidator;
use Domains\CapitalMarkets\Application\Service\CapitalMarketsRuntimeEvidenceGate;
use Kernel\Agent\AgentResult;

require dirname(__DIR__,2).'/vendor/autoload.php';

$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$reject=static function(callable $callback):bool{
    try{$callback();return false;}catch(InvalidArgumentException|DomainException){return true;}
};
$cases=[
    [CapitalMarketsMarketIntelligenceAgent::definition(),'observe'],
    [CapitalMarketsOpportunityAgent::definition(),'detect'],
    [CapitalMarketsRiskAgent::definition(),'assess'],
    [CapitalMarketsExecutionAgent::definition(),'execute'],
    [CapitalMarketsOrchestratorAgent::definition(),'measure'],
];
$validator=new CapitalMarketsSpecialistResultValidator();
foreach($cases as [$agent,$stage]){
    $assert($agent->domainName==='capital_markets','Wrong domain registration.');
    $assert($agent->maxActionsPerRun===0&&$agent->allowedActionTypes===[],
        'Specialist has unauthorized action capabilities.');
    $assert($agent->resultValidatorClass===CapitalMarketsSpecialistResultValidator::class,
        'Specialist is missing a runtime output validator.');
    $evidence=[$stage=>[
        'findings'=>['read-only persisted evidence'],
        'limitations'=>['human approval still required'],
        'evidence_refs'=>['cm-test-evidence'],
    ]];
    $validator->validate(new AgentResult('REVIEW','requires review',0.95,[],$evidence),$agent);
    $assert($reject(fn()=>$validator->validate(
        new AgentResult('PROPOSE','execute live',0.99,[
            ['type'=>'execute.live','parameters'=>[]],
        ],$evidence),$agent)),
        'Specialist allowed a live or business action proposal.');
    $assert($reject(fn()=>$validator->validate(
        new AgentResult('APPROVED','self approve',0.99,[],$evidence),$agent)),
        'Specialist emitted approval decision.');
    $assert($reject(fn()=>$validator->validate(
        new AgentResult('REVIEW','wrong stage',0.99,[],['other'=>$evidence[$stage]]),$agent)),
        'Specialist accepted another stage as evidence.');
}
$gates=new CapitalMarketsRuntimeEvidenceGate();
$assert($gates->observation(['sources'=>[]])==='NO_REGISTERED_MARKET_SOURCES',
    'No market sources did not block the workflow.');
$assert($gates->observation(['sources'=>[['id'=>'source']], 'states'=>[
    ['quality'=>['status'=>'STALE'],'mode'=>'LIVE'],
    ['quality'=>['status'=>'TRUSTED'],'mode'=>'DELAYED'],
]])==='NO_TRUSTED_LIVE_MARKET_EVIDENCE',
    'Stale or delayed market data erroneously authorized the workflow.');
$assert($gates->observation(['sources'=>[['id'=>'source']],'states'=>[
    ['quality_status'=>'TRUSTED','mode'=>'LIVE','source_timestamp'=>date(DATE_ATOM)],
]])===null,'Trustworthy live market evidence was rejected.');
$assert($gates->opportunity(null)==='OPPORTUNITY_NOT_PERSISTED',
    'Opportunity missing from DB did not block.');
$assert($gates->opportunity(['status'=>'REJECTED'])==='OPPORTUNITY_NOT_ELIGIBLE',
    'Rejected opportunity passed deterministic gate.');
$assert($gates->risk(['risk'=>[],'risk_envelope'=>[]])
    ==='DETERMINISTIC_RISK_ENVELOPE_MISSING','Missing risk envelope was approved.');
$assert($gates->risk(['risk'=>['x'=>1],'risk_envelope'=>['x'=>1],
    'capital_state'=>['x'=>1],'allocation_plan'=>['x'=>1]])===null,
    'Present deterministic risk and allocation evidence rejected.');
$assert($gates->measuredExecution(null,'opp')==='PAPER_EXECUTION_PENDING_HUMAN_APPROVAL',
    'No paper execution incorrectly proceeded to measurement.');
$assert($gates->measuredExecution(['opportunity_id'=>'other','status'=>'SETTLED'],'opp')
    ==='EXECUTION_OPPORTUNITY_MISMATCH','Unrelated paper execution accepted.');
$assert($gates->measuredExecution(['opportunity_id'=>'opp','status'=>'OPEN'],'opp')
    ==='PAPER_EXECUTION_NOT_FINALIZED','Open paper execution was measured.');
$assert($gates->measuredExecution(['opportunity_id'=>'opp','status'=>'SETTLED'],'opp')===null,
    'Settled paper execution incorrectly blocked.');
echo "Capital Markets runtime specialist and deterministic gates passed.\n";
