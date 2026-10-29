<?php
declare(strict_types=1);

use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\CapabilityContractFingerprint;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleDiscovery;
use Platform\Orchestration\Goal\GoalCapabilityPinGuard;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\GoalPlanValidator;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog(
    (new ModuleDiscovery($root . '/app/Domains'))->discover()
));
$check = static function (bool $ok,string $error):void {
    if (!$ok) throw new RuntimeException($error);
};
$reject = static function (callable $fn,string $message)use($check):void {
    try {
        $fn();
    } catch (DomainException) {
        return;
    }
    $check(false,$message);
};
$goal = new GoalSpecification('goal-test','tenant-1','manager-1','Native verified Sales task', [
    ['id'=>'tasks_created','operator'=>'at_least','expected'=>1],
],['sales.create_task']);
$input = [
    'target_type'=>'deal','target_id'=>'deal-1',
    'parameters'=>['title'=>'Follow up','due_in_minutes'=>60],
];
$step=['id'=>'task','capability_id'=>'sales.create_task','capability_version'=>'1.0.0','input'=>$input];
$validator = new GoalPlanValidator($catalog);
$verified = $validator->validate($goal,[$step],['sales.create_task'],true);
$check($verified['valid'], 'Real Sales typed capability must accept pinned Plan: '.implode(',',$verified['errors']));
$pinned = $verified['steps'][0];
$expected = $catalog->snapshot('sales.create_task',$root);
$check(($pinned['contract_digest']??null)===$expected['contract_digest'],
    'Plan did not embed the current registered contract digest.');
$check(($pinned['input_digest']??null)===CapabilityContractFingerprint::inputDigest($input),
    'Plan input digest does not match canonical input.');
$guard = new GoalCapabilityPinGuard($catalog);
$guard->assertPlan([$pinned]);
$reject(static fn()=>$catalog->resolvePinned('sales.create_task','1.0.0',str_repeat('0',64),$root),
    'Unknown digest must fail closed.');
$bad = $pinned;
$bad['input']['parameters']['title']='Changed after Plan Approval';
$reject(static fn()=>$guard->assertPlan([$bad]),'Changed Step input was accepted.');
$bad=$pinned;
$bad['contract_digest']=str_repeat('0',64);
$reject(static fn()=>$guard->assertPlan([$bad]),'Changed Capability fingerprint was accepted.');
$bad=$pinned;
$bad['owner_domain']='documents';
$reject(static fn()=>$guard->assertPlan([$bad]),'Changed binding owner was accepted.');
$bad=$pinned;
$bad['capability_version']='2.0.0';
$reject(static fn()=>$guard->assertPlan([$bad]),'Changed Capability version was accepted.');
$bad=$pinned;
unset($bad['input_digest']);
$reject(static fn()=>$guard->assertPlan([$bad]),'Partially pinned Step was accepted.');
$reject(static fn()=>$guard->assertPlan([$pinned,$step]),
    'Mixed new and legacy Plan was accepted.');
$guard->assertPlan([$step]); // Existing independently approved legacy Plans are not rewritten.
$check(!isset($step['contract_digest']), 'Legacy Plan mutated by read-only guard.');
$legacy=$validator->validate($goal,[$step],['sales.create_task']);
$check($legacy['valid'] && !array_key_exists('contract_digest',$legacy['steps'][0]),
    'Backward-compatible pure validator unexpectedly changed its default behavior.');
echo "Goal canonical Capability Plan fingerprint and legacy safety PASS.\n";
