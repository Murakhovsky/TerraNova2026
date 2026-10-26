<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;

$root=dirname(__DIR__,2);
require $root.'/vendor/autoload.php';
$catalog=new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root.'/app/Domains'))->discover()
));
$validate=new CapabilityJsonInputValidator();
$check=static function(bool $ok,string $why):void {
    if(!$ok) throw new RuntimeException($why);
};
$reject=static function(callable $fn,string $why)use($check):void {
    try {$fn();} catch(DomainException) {return;}
    $check(false,$why);
};
foreach (['growth.candidate.qualify','growth.handoff.prepare',
    'growth.handoff.target.sales','documents.proposal.prepare'] as $id) {
    $snapshot=$catalog->snapshot($id,$root);
    $check(preg_match('/^[a-f0-9]{64}$/D',$snapshot['contract_digest'])===1,
        'Golden 50 output schema did not compile for '.$id);
}
$qualify=$catalog->describe('growth.candidate.qualify');
$valid=[
    'candidate_id'=>'GC-1',
    'evaluation_id'=>'eval-1',
    'policy_id'=>'policy-1',
    'policy_revision'=>1,
    'outcome'=>'qualified',
    'goal_outcome_verified'=>false,
];
$validate->validateOutput($qualify,$valid);
$bad=$valid;
$bad['outcome']='synthetic_success';
$reject(static fn()=> $validate->validateOutput($qualify,$bad),
    'Golden 50 invalid qualification status passed output validation.');
$bad=$valid;
$bad['goal_outcome_verified']=true;
$reject(static fn()=> $validate->validateOutput($qualify,$bad),
    'Action handler may not self-attest a Goal outcome.');
$documentsInput=[
    'target_type'=>'growth_candidate','target_id'=>'GC-1',
    'parameters'=>['template_id'=>'tpl-1','variables'=>['candidate_id'=>'GC-1']],
];
$documentContract=$catalog->describe('documents.proposal.prepare');
$validate->validate($documentContract,$documentsInput);
$badInput=$documentsInput;
$badInput['parameters']['variables']['candidate_id']=42;
$reject(static fn()=> $validate->validate($documentContract,$badInput),
    'Numeric untyped Document variable bypassed dynamic map schema.');
$badInput=$documentsInput;
$badInput['parameters']['variables']['candidate_id']=str_repeat('x',4001);
$reject(static fn()=> $validate->validate($documentContract,$badInput),
    'Oversized Document variable bypassed maxLength.');
$document=$catalog->describe('documents.proposal.prepare');
$prepared=[
    'document_id'=>'doc-1','relation_id'=>'rel-1','sales_lead_id'=>'5',
    'candidate_id'=>'GC-1','status'=>'prepared_not_sent','goal_outcome_verified'=>false,
];
$validate->validateOutput($document,$prepared);
$bad=$prepared;
$bad['status']='signed_and_sent';
$reject(static fn()=> $validate->validateOutput($document,$bad),
    'Prepared Document output may not claim transmission or signature.');
echo "Golden 50 governed Action output enum/const schema profile PASS.\n";
