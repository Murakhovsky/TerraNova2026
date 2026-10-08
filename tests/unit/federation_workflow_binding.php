<?php
declare(strict_types=1);

use Kernel\Shared\Domain\OrganizationId;
use Kernel\Workflow\Model\Assignment;
use Kernel\Workflow\Model\AssignmentType;
use Kernel\Workflow\Model\Step\HumanStep;
use Kernel\Workflow\Model\Step\SystemStep;
use Kernel\Workflow\Model\Workflow;
use Kernel\Workflow\Model\WorkflowDefinition;
use Kernel\Workflow\Model\WorkflowInstance;
use Platform\Orchestration\Goal\GoalSpecification;
use Platform\Orchestration\Goal\GoalWorkflowBindingGuard;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$goal = new GoalSpecification(
    'goal-gate', 'tenant-one', 'user-one', 'Produce verified demand',
    [['id' => 'leads', 'operator' => 'at_least', 'expected' => 2]],
    ['sales.leads.review'],
);
$guard = new GoalWorkflowBindingGuard();
$workflow = new WorkflowInstance(
    'wf-instance', OrganizationId::fromString('tenant-one'),
    new Workflow('goal.guard', new WorkflowDefinition(
        'goal.guard', '1.0.0', 'Approval-only gate', 'gate', [new HumanStep('gate', 'Review', new Assignment(AssignmentType::ROLE, 'manager'))],
    )),
);
$steps = [['id' => 'gate', 'capability_id' => 'sales.leads.review',
    'capability_version' => '1.0.0', 'side_effect_level' => 'none']];
$guard->assertCompatible($goal, $steps, $workflow, ['sales.leads.review']);
$reject = static function (callable $callback, string $message): void {
    try { $callback(); } catch (DomainException) { return; }
    throw new RuntimeException('Federation binding guard accepted: ' . $message);
};
$reject(fn () => $guard->assertCompatible($goal, $steps, $workflow, []), 'missing approval');
$reject(fn () => $guard->assertCompatible($goal, $steps, new WorkflowInstance(
    'foreign', OrganizationId::fromString('tenant-two'), $workflow->workflow,
), ['sales.leads.review']), 'other tenant');
$guard->assertCompatible($goal, $steps, new WorkflowInstance(
    'read_only_system', OrganizationId::fromString('tenant-one'),
    new Workflow('goal.guard', new WorkflowDefinition('goal.guard', '1.0.0',
        'Safe checkpoint', 'gate', [new SystemStep('gate', 'Checkpoint', 'federation.read_only.checkpoint')])),
), ['sales.leads.review']);
$reject(fn () => $guard->assertCompatible($goal, $steps, new WorkflowInstance(
    'system', OrganizationId::fromString('tenant-one'),
    new Workflow('goal.guard', new WorkflowDefinition('goal.guard', '1.0.0',
        'Hidden system effect', 'gate', [new SystemStep('gate', 'System operation', 'send')])),
), ['sales.leads.review']), 'unbound SystemStep');
$reject(fn () => $guard->assertCompatible($goal, [['id' => 'different',
    'capability_id' => 'sales.leads.review', 'capability_version' => '1.0.0',
    'side_effect_level' => 'none']], $workflow, ['sales.leads.review']), 'step drift');
echo "Federation Workflow binding gate passed: approval, tenant, topology, unsafe step guards.\n";
