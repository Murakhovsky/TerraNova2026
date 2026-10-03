<?php
declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\Engineering\\')) return;
    $path = dirname(__DIR__, 2).'/symfony/src/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($path)) require $path;
});

use App\Engineering\Application\Workflow\EngineeringWorkflowCoordinator;
use App\Engineering\Application\Workflow\WorkflowCounters;
use App\Engineering\Application\Workflow\WorkflowDirectiveType;
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\ReadyForHumanApprovalEvidence;
use App\Engineering\Domain\Workflow\WorkflowExecution;

$workflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::NEW, 'golden-trace');
$coordinator = new EngineeringWorkflowCoordinator();

$d = $coordinator->startAnalysis($workflow);
if ($d->agent !== AgentRole::ENGINEERING_MANAGER) throw new RuntimeException('Manager was not scheduled.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::ENGINEERING_MANAGER, ['status' => 'SPECIFICATION_READY']);
if ($d->agent !== AgentRole::PRINCIPAL_ARCHITECT || $workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) throw new RuntimeException('Architect routing failed.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::PRINCIPAL_ARCHITECT, ['status' => 'APPROVED']);
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('Developer routing failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'CHANGES_REQUESTED'], new WorkflowCounters(0, 1, 0));
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('Review fix routing failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters(1, 2, 0));
if ($d->agent !== AgentRole::QA || $workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) throw new RuntimeException('QA routing failed.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA, ['status' => 'FAIL'], new WorkflowCounters(1, 2, 1));
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('QA fix routing failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters(2, 3, 1));

$ready = new ReadyForHumanApprovalEvidence(true, true, true, true, true, true, false, false, false);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA, ['status' => 'PASS'], new WorkflowCounters(2, 3, 2), $ready);

if ($d->type !== WorkflowDirectiveType::READY_FOR_HUMAN_APPROVAL || $workflow->currentState() !== EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
    throw new RuntimeException('Golden workflow did not reach READY_FOR_HUMAN_APPROVAL.');
}

echo "Engineering golden workflow passed.\n";
