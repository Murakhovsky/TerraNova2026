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
use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Workflow\EngineeringId;
use App\Engineering\Domain\Workflow\EngineeringWorkflowState;
use App\Engineering\Domain\Workflow\WorkflowExecution;

$workflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::NEW, 'agent-model-v2');
$coordinator = new EngineeringWorkflowCoordinator();

$d = $coordinator->startAnalysis($workflow);
if ($d->agent !== AgentRole::ENGINEERING_MANAGER) throw new RuntimeException('V2 must start with Engineering Manager.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::ENGINEERING_MANAGER, [
    'status' => 'SPECIFICATION_READY',
    'product_handoff_required' => true,
]);
if ($d->agent !== AgentRole::PRODUCT_REQUIREMENTS || $workflow->currentState() !== EngineeringWorkflowState::ANALYSIS) {
    throw new RuntimeException('Manager must hand V2 analysis to Product / Requirements without advancing the state.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::PRODUCT_REQUIREMENTS, ['status' => 'SPECIFICATION_READY']);
if ($d->agent !== AgentRole::QA_PLANNER || $workflow->currentState() !== EngineeringWorkflowState::QA_PLANNING) {
    throw new RuntimeException('Product / Requirements must hand authoritative AC to QA Planner.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA_PLANNER, ['status' => 'PLAN_READY']);
if ($d->agent !== AgentRole::PRINCIPAL_ARCHITECT || $workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
    throw new RuntimeException('QA Planner must hand the testable contract to Principal Architect.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::PRINCIPAL_ARCHITECT, ['status' => 'APPROVED']);
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) {
    throw new RuntimeException('Principal Architect must hand approved implementation work to Developer.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
if ($d->agent !== AgentRole::REVIEWER || $workflow->currentState() !== EngineeringWorkflowState::REVIEW_PENDING) {
    throw new RuntimeException('Developer completion must require independent Reviewer.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters());
if ($d->agent !== AgentRole::QA_EXECUTOR || $workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) {
    throw new RuntimeException('Reviewer approval must route to QA Executor.');
}



// ER2-AC-025: persisted/legacy Feature Engineering workflows remain executable.
$legacy = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::NEW, 'feature-v01-regression');
$coordinator->startAnalysis($legacy);
$legacyDirective = $coordinator->acceptAgentResult($legacy, AgentRole::ENGINEERING_MANAGER, ['status' => 'SPECIFICATION_READY']);
if ($legacyDirective->agent !== AgentRole::QA || $legacy->currentState() !== EngineeringWorkflowState::QA_PLANNING) {
    throw new RuntimeException('ER2-AC-025 regression: legacy Manager → QA planning route no longer works.');
}
$legacyDirective = $coordinator->acceptAgentResult($legacy, AgentRole::QA, ['status' => 'PLAN_READY']);
if ($legacyDirective->agent !== AgentRole::PRINCIPAL_ARCHITECT || $legacy->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
    throw new RuntimeException('ER2-AC-025 regression: legacy QA planning route no longer reaches Architect.');
}

echo "Engineering V2 agent workflow and Feature Runtime regression passed.\n";
