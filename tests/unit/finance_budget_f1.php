<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\Finance\Domain\BudgetControlPolicy;
use Domains\Finance\Automation\Action\FinanceBudgetActionHandler;
use Domains\Finance\Bootstrap\FinanceDomainModule;
use Kernel\Module\CapabilityContract;
use Kernel\Module\ModuleDefinition;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $ok,string $why):void {if(!$ok)throw new RuntimeException($why);};
$policy=new BudgetControlPolicy();
$valid=[
    'name'=>'Meta planning reserve',
    'source_domain'=>'marketing',
    'source_ref'=>'mkt-audit-100',
    'currency'=>'USD','period_start'=>'2026-11-01','period_end'=>'2026-11-30',
    'limit_minor'=>100000
];
$draft=$policy->draft($valid);
$assert($draft['limit_minor']===100000&&$draft['source_domain']==='marketing','F1 policy valid draft');
foreach([
    [...$valid,'limit_minor'=>100.50],
    [...$valid,'limit_minor'=>-1],
    [...$valid,'currency'=>'usd'],
    [...$valid,'period_start'=>'2026-12-20','period_end'=>'2026-11-30'],
    [...$valid,'source_domain'=>'other'],
    [...$valid,'organization_id'=>'forged'],
    [...$valid,'limit_minor'=>1_000_000_000_001],
] as $bad) {
    try {$policy->draft($bad);}catch(DomainException){continue;}
    throw new RuntimeException('Finance rejected budget input was accepted.');
}
foreach([
    [100.00,1,'marketing','provider-key-123','reason'],
    [100,0,'marketing','provider-key-123','reason'],
    [100,1,'other','provider-key-123','reason'],
    [100,1,'marketing','short','reason'],
] as $args){
    try {$policy->reserveInput(...$args);}catch(DomainException){continue;}
    throw new RuntimeException('Finance invalid reservation was accepted.');
}
$good=$policy->reserveInput(100,1,'marketing','provider-key-123','Allowed');
$assert($good['amount_minor']===100,'Finance minor unit policy');
$assert(BudgetControlPolicy::id('finb-'.str_repeat('a',24))!=='','Finance valid budget reference');
try{BudgetControlPolicy::id('finr-'.str_repeat('a',24));}catch(DomainException){$blocked=true;}
$assert($blocked??false,'Reserve ID cannot impersonate an envelope');

$manifest=require $root.'/app/Domains/Finance/module.php';
$module=ModuleDefinition::fromArray($manifest);
$assert($module->manifest->id==='finance'&&!$module->manifest->enabledByDefault,'Finance F1 must be disabled by default');
$assert($module->contributions->runtimeModuleService==='financeDomainModule','Finance F1 missing runtime owner');
$assert(count($module->contributions->capabilityContracts)===17,'Expected seven budget, three source-evidence, four order collection and three settlement Finance capabilities');
$typed=[];
foreach($module->contributions->capabilityContracts as $c){
    $typed[$c->id]=$c;
    $assert(is_file($root.'/'.$c->inputSchema),'Missing Finance schema '.$c->inputSchema);
    $assert(is_file($root.'/'.$c->outputSchema),'Missing Finance schema '.$c->outputSchema);
    if(str_starts_with($c->executionBinding,'action:')){
        $expectedSideEffect=$c->id==='finance.ad_spend.marketing_snapshot.import'?'internal':'financial';
        $assert($c->sideEffectLevel===$expectedSideEffect&&$c->approvalPolicy==='required'
            &&$c->idempotency==='required','Financial capability must require approval and idempotency');
    }
}
$assert($typed['finance.sales.commercial_evidence.read']->executionBinding==='query:finance.sales.commercial_evidence.read' && $typed['finance.sales.commercial_evidence.read']->sideEffectLevel==='none','Sales evidence must remain read-only');
$validator=new CapabilityJsonInputValidator();
$validator->validate($typed['finance.budget.envelope.draft.create'],$valid);
$validator->validate($typed['finance.budget.reservation.reserve'],[
    'envelope_id'=>'finb-'.str_repeat('a',24),'amount_minor'=>100,
    'expected_revision'=>2,'source_domain'=>'marketing','source_operation_key'=>'provider-key-123','reason'=>'Approved'
]);
try{
    $validator->validate($typed['finance.budget.reservation.reserve'],[
        'envelope_id'=>'finb-'.str_repeat('a',24),'amount_minor'=>100.1,
        'expected_revision'=>2,'source_domain'=>'marketing','source_operation_key'=>'provider-key-123','reason'=>'Approved'
    ]);
}catch(DomainException){$schemaBlocked=true;}
$assert($schemaBlocked??false,'Finance schema must reject floats');
$assert(in_array('finance.budget.reservation.release',$manifest['contributions']['capabilities'],true),'Finance release unavailable');
$settlement=$typed['finance.ad_spend.settlement.commit'];
$assert($settlement->sideEffectLevel==='financial'&&$settlement->approvalPolicy==='required'&&$settlement->idempotency==='required','Settlement must preserve governed financial class.');
$settlementInput=['target_type'=>'finance_reservation','target_id'=>'finr-'.str_repeat('a',24),'parameters'=>[
    'candidate_hash'=>str_repeat('b',64),'document_ref'=>'DOC-'.str_repeat('c',24),'expected_revision'=>4,'snapshot_ref'=>'mis-'.str_repeat('d',24)]];
$validator->validate($settlement,$settlementInput);
$blocked=false;try{$validator->validate($settlement,[...$settlementInput,'parameters'=>[...$settlementInput['parameters'],'settlement_minor'=>1000]]);}catch(DomainException){$blocked=true;}
$assert($blocked,'Settlement Action must not accept caller-supplied amounts.');
$review=$typed['finance.ad_spend.settlement.review'];$status=$typed['finance.ad_spend.settlement.status'];
$assert($review->sideEffectLevel==='internal'&&$review->idempotency==='required'&&$status->kind==='query'&&$status->sideEffectLevel==='none',
    'Review is human evidence, status is read only.');
$reviewInput=['reservation_id'=>'finr-'.str_repeat('a',24),'document_ref'=>'DOC-'.str_repeat('c',24),'snapshot_ref'=>'mis-'.str_repeat('d',24),
    'document_hash'=>str_repeat('e',64),'source_hash'=>str_repeat('f',64),'source_revision'=>1,'provider_document_id'=>'fixture-doc',
    'settlement_minor'=>1000,'confirm_review'=>true];
$validator->validate($review,$reviewInput);
$blocked=false;try{$validator->validate($review,[...$reviewInput,'confirm_review'=>false]);}catch(DomainException){$blocked=true;}
$assert($blocked,'Review requires explicit human confirmation.');
$approvedEvidence=$typed['finance.ad_spend.marketing_snapshot.import'];
$assert($approvedEvidence->executionBinding==='action:finance.ad_spend.marketing_snapshot.import'&&$approvedEvidence->sideEffectLevel==='internal'&&$approvedEvidence->approvalPolicy==='required'&&$approvedEvidence->idempotency==='required','Source-only import must remain independently approved canonical Action.');
echo "Finance F1 bounded amounts, approval schemas and capability contracts PASS\n";

