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
$assert(str_contains($source,'NOT exposed as a Capability writer') &&
    str_contains($source,'acceptUnverifiedMarketingSnapshot') &&
    str_contains($source,'$this->policy->validate($marketingSnapshot)'),'Spend observation source cannot advertise a live public write API.');
echo "Finance F1 source spend evidence, exact query scope and no false Expense PASS\n";

