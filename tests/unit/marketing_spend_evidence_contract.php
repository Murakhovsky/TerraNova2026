<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';

use Kernel\Module\CapabilityContract;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$assert=static function(bool $okay,string $why):void {if(!$okay)throw new RuntimeException($why);};
$raw=require $root.'/app/Domains/Marketing/module.php';
$contracts=[];
foreach($raw['contributions']['capability_contracts'] as $def) {
    $c=CapabilityContract::fromArray($def);
    $contracts[$c->id]=$c;
}
$c=$contracts['marketing.paid.spend.evidence.get']??null;
$assert($c instanceof CapabilityContract,'Finance-facing canonical Marketing evidence Query missing.');
$assert($c->ownerDomain==='marketing'
    &&$c->kind==='query'
    &&$c->sideEffectLevel==='none'
    &&$c->approvalPolicy==='none'
    &&$c->executionBinding==='query:marketing.paid.spend.evidence.get',
    'Marketing evidence cannot be a mutation or foreign source of financial authority.');
$validator=new CapabilityJsonInputValidator();
$validator->validate($c,[
    'account_id'=>'mads-'.str_repeat('a',24),
    'source_snapshot_ref'=>'mis-'.str_repeat('b',24)
]);
$invalid=[
    ['account_id'=>'mads-'.str_repeat('a',24),'source_snapshot_ref'=>'mis-../../escape'],
    ['account_id'=>'mads-'.str_repeat('a',24),'source_snapshot_ref'=>'mis-'.str_repeat('b',24),'organization_id'=>'foreign'],
    ['account_id'=>'other','source_snapshot_ref'=>'mis-'.str_repeat('b',24)],
    ['account_id'=>'mads-'.str_repeat('a',24),'source_snapshot_ref'=>'mis-'.str_repeat('b',24),'finance_expense_confirmed'=>true],
];
foreach($invalid as $input){
    try{$validator->validate($c,$input);}
    catch(DomainException){continue;}
    throw new RuntimeException('Marketing evidence Query accepted privilege or forged input.');
}
$schema=json_decode((string)file_get_contents($root.'/'.$c->outputSchema),true,32,JSON_THROW_ON_ERROR);
$assert($schema['properties']['finance_expense_confirmed']['const']===false
    &&$schema['properties']['readback_status']['const']==='MARKETING_DB_SNAPSHOT',
    'Output contract must not fabricate Finance Expense or provider attestation.');
$fields=array_keys($schema['properties']['evidence']['properties']);
$assert(count($fields)===13&&$schema['properties']['evidence']['additionalProperties']===false,
    'Finance evidence output payload must have exactly 13 supported source fields.');
$assert($raw['enabled_by_default']===false,'Unaccepted Marketing feature cannot be enabled by default.');
echo "Marketing typed source spend evidence Query, no Finance write and strict provenance PASS\n";
