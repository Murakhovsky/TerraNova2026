<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';

use Platform\Workflow\Service\WorkflowStepScheduler;
use Platform\Workflow\Model\WorkflowDefinitionSnapshot;
use Platform\Workflow\Model\WorkflowStepSnapshot;
use Platform\Workflow\Model\WorkflowStepSelectionState;
use Platform\Workflow\Model\WorkflowEvidenceSignal;
use Kernel\Workflow\Model\StepStatus;
use Platform\Workflow\Service\DurableWorkflowTransitionPolicy;

$steps=[
    ['id'=>'await','capability_id'=>'test.read','capability_version'=>'1',
      'side_effect_level'=>'none','depends_on'=>[]],
    ['id'=>'next','capability_id'=>'test.publish','capability_version'=>'1',
      'side_effect_level'=>'external','depends_on'=>['await']],
];
$rows=[
    new WorkflowStepSnapshot('await','test.read','1','none','waiting',1,3),
    new WorkflowStepSnapshot('next','test.publish','1','external','pending'),
];
$result=(new WorkflowStepScheduler())->select(
    new WorkflowDefinitionSnapshot('test','1',$steps),$rows,['none','external']);
if($result->state!==WorkflowStepSelectionState::WAITING || $result->stepId!=='await') {
    throw new RuntimeException('Wait must block DAG Action dispatch until independently verified.');
}
if(!DurableWorkflowTransitionPolicy::canTransition(
    StepStatus::CLAIMED->value,StepStatus::WAITING->value,true)) {
    throw new RuntimeException('Wait technical transition unavailable.');
}
if(DurableWorkflowTransitionPolicy::canAutoRetry('external','waiting')) {
    throw new RuntimeException('External wait must never automatically retry.');
}
if(!interface_exists(\Platform\Workflow\Contract\WorkflowEvidenceSignalStoreInterface::class)
    || !class_exists(WorkflowEvidenceSignal::class)) {
    throw new RuntimeException('Wait/Signal canonical Store contract missing.');
}
echo "Workflow Wait/Signal contract passed: blocked DAG, no external replay, bounded evidence port.\n";
