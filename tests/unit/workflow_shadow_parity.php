<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Kernel\Workflow\Model\WorkflowStatus;
use Platform\Workflow\Model\WorkflowParityStatus;
use Platform\Workflow\Model\WorkflowRunIdentity;
use Platform\Workflow\Model\WorkflowRunSnapshot;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Service\WorkflowShadowParityComparator;

$assert = static function (bool $ok, string $reason): void {
    if (!$ok) throw new RuntimeException($reason);
};
$org = 'tenant-shadow';
$runId = 'federation-case';
$planDigest = hash('sha256','approved-plan');
$run = new WorkflowRunSnapshot(new WorkflowRunIdentity($org,$runId),
    'approved.workflow','1',WorkflowStatus::RUNNING,2,'corr',
    'plan-1',null,$planDigest);
$key = hash('sha256', $org . "\0" . $runId . "\0" . 'step-1');
$steps = [new WorkflowStepSnapshot('step-1','crm.create','1','external','completed',1,2,
    'action:abc',null,$key)];
$legacy = [
    'organization_id'=>$org,'run_id'=>$runId,'state'=>'running',
    'plan_id'=>'plan-1','revision'=>2,
    'steps'=>[['step_id'=>'step-1','capability_id'=>'crm.create',
        'capability_version'=>'1','side_effect_level'=>'external',
        'state'=>'completed','attempts'=>1,'idempotency_key'=>$key,
        'result_reference'=>'action:abc']],
];
$c = new WorkflowShadowParityComparator();
$assert($c->federation($legacy,null,$steps)->status === WorkflowParityStatus::NO_TARGET, 'Missing target should not be equal.');
$assert($c->federation($legacy,$run,$steps)->status === WorkflowParityStatus::PARTIAL, 'Unattested Plan/Action should not be green.');
$assert($c->federation($legacy,$run,$steps,$planDigest,['step-1'=>'action:abc'])->status === WorkflowParityStatus::MATCH, 'Pinned matching projections should agree.');
$altered=$legacy;
$altered['steps'][0]['attempts']=2;
$assert($c->federation($altered,$run,$steps,$planDigest,['step-1'=>'action:abc'])->status === WorkflowParityStatus::DRIFT, 'Attempt mismatch not reported.');
$altered=$legacy;
$altered['steps'][0]['idempotency_key']=hash('sha256','replayed');
$assert($c->federation($altered,$run,$steps,$planDigest,['step-1'=>'action:abc'])->status === WorkflowParityStatus::DRIFT, 'Changed Action key not detected.');
$altered=$legacy;
$altered['organization_id']='foreign';
$assert($c->federation($altered,$run,$steps,$planDigest,['step-1'=>'action:abc'])->status === WorkflowParityStatus::DRIFT, 'Tenant mismatch not detected.');
$altered=$legacy;
$altered['steps'][]=$altered['steps'][0];
$assert($c->federation($altered,$run,$steps,$planDigest,['step-1'=>'action:abc'])->status === WorkflowParityStatus::DRIFT, 'Duplicate legacy Step should not match.');
$eng = ['id'=>'engineering-1','status'=>'WAITING','trace_id'=>'engine-trace',
    'state'=>'HUMAN_DECISION_REQUIRED','feature_id'=>'feature-1','version'=>7];
$target = new WorkflowRunSnapshot(new WorkflowRunIdentity($org,'tech-1'),
    'engineering.stage','1',WorkflowStatus::WAITING,2,'engine-trace');
$assert($c->engineering($eng,$target,'engineering-1',$org)->status === WorkflowParityStatus::PARTIAL,
    'Engineering business stage/approval evidence must not claim full parity.');
$assert($c->engineering($eng,$target,'engineering-1',null)->status === WorkflowParityStatus::DRIFT,
    'Engineering tenant provenance required.');
$assert($c->engineering($eng,$target,'engineering-1','foreign-tenant')->status === WorkflowParityStatus::DRIFT,
    'Engineering cross-tenant evidence must fail closed.');
$assert($c->engineering($eng,$target,null,$org)->status === WorkflowParityStatus::DRIFT,
    'Engineering projection needs trusted legacy link.');
$assert($c->engineering($eng,null,'engineering-1',$org)->status === WorkflowParityStatus::NO_TARGET,
    'Unmigrated Engineering technical run not to be inferred.');
echo "Three-way Workflow shadow parity passed: source links, Action keys, immutable digests, drift and Engineering partial state.\n";
