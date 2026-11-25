<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Domains\Finance\Domain\FinanceSpendObservationPolicy;
use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $ok,string $msg):void {if(!$ok)throw new RuntimeException($msg);};
$blocked=static function(callable $operation,string $message):void {
    try{$operation();}catch(DomainException){return;}
    throw new RuntimeException($message);
};
$policy=new FinanceSpendObservationPolicy();
$valid=[
    'ad_account_ref'=>'mads-'.str_repeat('a',24),
    'source_snapshot_ref'=>'mis-'.str_repeat('b',24),
    'grain'=>'campaign','external_id'=>'123456789',
    'window_start'=>'2026-11-01','window_end'=>'2026-11-07',
    'timezone'=>'UTC','currency'=>'USD',
    'attribution_hash'=>str_repeat('c',64),'source_hash'=>str_repeat('d',64),
    'source_revision'=>1,'source_status'=>'PROVISIONAL',
    'reported_spend_decimal'=>'10.004100'
];
$normalized=$policy->validate($valid);
$assert($normalized['evidence_verification']==='UNVERIFIED_SOURCE'
    &&$normalized['reported_spend_decimal']==='10.004100','Meta evidence must stay as decimal text');
foreach([
    [...$valid,'organization_id'=>'attacker-org'],
    [...$valid,'provider'=>'self-claimed'],
    [...$valid,'source_revision'=>0],
    [...$valid,'reported_spend_decimal'=>10.1],
    [...$valid,'reported_spend_decimal'=>'10.0000001'],
    [...$valid,'reported_spend_decimal'=>'NaN'],
    [...$valid,'reported_spend_decimal'=>'-1'],
    [...$valid,'grain'=>'account'],
    [...$valid,'window_start'=>'2026-02-30'],
    [...$valid,'window_end'=>'2026-12-20'],
    [...$valid,'currency'=>'usd'],
    [...$valid,'ad_account_ref'=>'other-tenant'],
] as $invalid){
    $blocked(static fn()=> $policy->validate($invalid),'Untrusted source or noncanonical spend evidence accepted.');
}
$scope=$policy->scope($valid['ad_account_ref'],$valid['window_start'],$valid['window_end'],'USD','UTC',$valid['attribution_hash']);
$assert(count($scope)===6 && !isset($scope['source_hash']),'Scope must not contain unverified provider value.');

$manifest=require $root.'/app/Domains/Finance/module.php';
$assert($manifest['enabled_by_default']===false,'Finance still must remain disabled for tenants.');
$found=null;
foreach($manifest['contributions']['capability_contracts'] as $raw){
    if($raw['id']==='finance.ad_spend.reconciliation.status')$found=CapabilityContract::fromArray($raw);
}
$assert($found!==null&&$found->kind==='query'&&$found->sideEffectLevel==='none',
    'Finance reconciliation read must not be a financial mutation.');
(new CapabilityJsonInputValidator())->validate($found,$scope);
$blocked(static fn()=> (new CapabilityJsonInputValidator())->validate($found,[
    ...$scope,'finance_expense_confirmed'=>true
]),'Caller must not forge financial expense in read query');
$source=(string)file_get_contents($root.'/app/Domains/Finance/Application/Service/FinanceSpendObservationService.php');
$repository=(string)file_get_contents($root.'/app/Domains/Finance/Infrastructure/Persistence/MySql/MysqlFinanceSpendObservationRepository.php');
$assert(str_contains($source,'NOT exposed as a Capability writer') &&
    str_contains($source,'acceptUnverifiedMarketingSnapshot') &&
    str_contains($source,'$this->repository->record($organizationId,$marketingSnapshot,$correlationId)') &&
    str_contains($repository,'$this->policy->validate($raw)'),
    'Private Finance ingress must delegate to the one strict persistence-owned evidence validator.');

/** M3 final settlement preparation cannot mutate Finance or accept source status as a document. */
$settlement=new \Domains\Finance\Domain\FinancePaidSettlementPolicy();
$org='settlement-fixture';$ph=str_repeat('a',64);$planId='mkpl-'.str_repeat('1',24);
$claim=['organization_id'=>$org,'source_ref'=>$planId,'plan_hash'=>$ph,'currency'=>'USD',
    'reservation_id'=>'finr-'.str_repeat('2',24),'envelope_id'=>'finb-'.str_repeat('3',24),
    'action_id'=>str_repeat('4',32),'actor_id'=>'41','amount_minor'=>3000];
$plan=['organization_id'=>$org,'id'=>$planId,'plan_hash'=>$ph,'currency'=>'USD','lifetime_minor'=>3000,
    'account_timezone'=>'UTC','account_id'=>$valid['ad_account_ref'],'period_start'=>'2020-01-01','period_end'=>'2020-01-07'];
$terminal=['organization_id'=>$org,'plan_id'=>$planId,'activation_action_id'=>$claim['action_id'],
    'state'=>'PAUSED_CONFIRMED','provider_campaign_id'=>$valid['external_id'],'pause_action_id'=>str_repeat('5',32),'unresolved_operations'=>[]];
$observation=[...$valid,'organization_id'=>$org,'window_start'=>$plan['period_start'],'window_end'=>$plan['period_end'],
    'reported_spend_decimal'=>'10.000000','source_status'=>'FINALIZED_BY_SOURCE'];
$document=[...$observation,'provider'=>'meta','document_state'=>'INDEPENDENTLY_REVIEWED_FINAL',
    'allocation_scope'=>'EXACT_CAMPAIGN_NET_MEDIA_SPEND','plan_hash'=>$ph,'pause_action_id'=>$terminal['pause_action_id'],
    'document_sha256'=>str_repeat('6',64),'document_ref'=>'fixture-doc','provider_document_id'=>'fixture-invoice',
    'review_ref'=>'fixture-review','reviewer_id'=>'42','settlement_minor'=>1000];
$candidate=$settlement->prepare($org,$claim,$plan,$terminal,$observation,$document);
$assert($candidate['state']==='MATCHED_NOT_SETTLED'&&$candidate['proposed_commit_minor']===1000
    &&$candidate['proposed_release_minor']===2000&&!$candidate['finance_settled']&&!$candidate['expense_confirmed']
    &&!$candidate['payment_confirmed']&&!$candidate['budget_mutated'],'Candidate must be exact and non-mutating.');
$assert($candidate===$settlement->prepare($org,$claim,$plan,$terminal,$observation,$document),'Candidate not deterministic.');
foreach([
    [...$document,'organization_id'=>'foreign-tenant'],
    [...$document,'document_state'=>'PROVISIONAL'],
    [...$document,'external_id'=>'99999'],
    [...$document,'currency'=>'EUR'],
    [...$document,'source_revision'=>2],
    [...$document,'source_hash'=>str_repeat('7',64)],
    [...$document,'reviewer_id'=>'41'],
    [...$document,'settlement_minor'=>1001],
    [...$document,'settlement_minor'=>10.0],
    [...$document,'allocation_scope'=>'ACCOUNT_TOTAL'],
] as $bad)$blocked(static fn()=> $settlement->prepare($org,$claim,$plan,$terminal,$observation,$bad),'Unsafe financial candidate accepted.');
$blocked(static fn()=> $settlement->prepare($org,$claim,$plan,[...$terminal,'unresolved_operations'=>['ACTIVATE']],$observation,$document),'Uncertain provider operation accepted.');
$blocked(static fn()=> $settlement->prepare($org,$claim,$plan,$terminal,[...$observation,'reported_spend_decimal'=>'10.000001'],$document),'Implicit rounding accepted.');
$blocked(static fn()=> $settlement->prepare($org,$claim,$plan,$terminal,[...$observation,'reported_spend_decimal'=>'31.000000'],[...$document,'settlement_minor'=>3100]),'Spend above held exposure accepted.');
$zero=$settlement->prepare($org,$claim,$plan,$terminal,[...$observation,'reported_spend_decimal'=>'0.000000'],[...$document,'settlement_minor'=>0]);
$assert($zero['proposed_release_minor']===3000&&!$zero['finance_settled'],'Zero spend must still remain only a candidate.');

echo "Finance F1 source spend evidence, exact query scope and M3 settlement candidate PASS\n";

