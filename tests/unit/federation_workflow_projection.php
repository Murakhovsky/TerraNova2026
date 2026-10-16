<?php
declare(strict_types=1);

use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Workflow\Model\Assignment;
use Kernel\Workflow\Model\AssignmentType;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Workflow;
use Kernel\Workflow\Model\WorkflowDefinition;
use Kernel\Workflow\Model\WorkflowExecution;
use Kernel\Workflow\Model\WorkflowInstance;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\GoalWorkflowProjection;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$goal = new GoalSpecification('goal-1', 'tenant-a', 'user-a', 'Human-reviewed task', [
    ['id' => 'confirmed', 'operator' => 'equals', 'expected' => true],
], ['operations.review']);
$workflow = new Workflow('goal.review', new WorkflowDefinition('goal.review', '1.0.0',
    'Review', 'review', [new HumanStep('review', 'Human review',
    new Assignment(AssignmentType::ROLE, 'manager'))]));
$instance = new WorkflowInstance('instance-1', OrganizationId::fromString('tenant-a'), $workflow);
$run = new WorkflowExecution('wf-run-1', $instance);
$projected = (new GoalWorkflowProjection())->project($goal, $run, ['review']);
if ($projected['run_state'] !== 'pending' || $projected['steps'] !== []
    || $projected['workflow_id'] !== 'wf-run-1') {
    throw new RuntimeException('Canonical Workflow state was not projected correctly.');
}
try {
    (new GoalWorkflowProjection())->project($goal, new WorkflowExecution('other',
        new WorkflowInstance('other-instance', OrganizationId::fromString('tenant-b'), $workflow)), ['review']);
    throw new RuntimeException('Workflow tenant isolation failed.');
} catch (DomainException) {
}
echo "Federation Workflow projection tenant and state invariants passed.\n";
