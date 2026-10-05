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
if ($d->agent !== AgentRole::QA || $workflow->currentState() !== EngineeringWorkflowState::QA_PLANNING) throw new RuntimeException('QA planning routing failed.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA, ['status' => 'PLAN_READY']);
if ($d->agent !== AgentRole::PRINCIPAL_ARCHITECT || $workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) throw new RuntimeException('Architect routing after QA plan failed.');

$humanWorkflow = new WorkflowExecution(EngineeringId::generate(), EngineeringId::generate(), EngineeringWorkflowState::NEW, 'architect-human-trace');
$coordinator->startAnalysis($humanWorkflow);
$coordinator->acceptAgentResult($humanWorkflow, AgentRole::ENGINEERING_MANAGER, ['status' => 'SPECIFICATION_READY']);
$coordinator->acceptAgentResult($humanWorkflow, AgentRole::QA, ['status' => 'PLAN_READY']);
$humanDirective = $coordinator->acceptAgentResult($humanWorkflow, AgentRole::PRINCIPAL_ARCHITECT, ['status' => 'NEEDS_HUMAN_DECISION']);
if ($humanDirective->type !== WorkflowDirectiveType::REQUEST_HUMAN_DECISION || $humanWorkflow->currentState() !== EngineeringWorkflowState::HUMAN_DECISION_REQUIRED) {
    throw new RuntimeException('Architect human decision gate failed.');
}

$d = $coordinator->acceptAgentResult($workflow, AgentRole::PRINCIPAL_ARCHITECT, ['status' => 'APPROVED']);
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('Developer routing failed.');

$revalidation = $coordinator->revalidateArchitecture($workflow, 'main advanced');
if ($revalidation->agent !== AgentRole::PRINCIPAL_ARCHITECT || $workflow->currentState() !== EngineeringWorkflowState::ARCHITECTURE_PENDING) {
    throw new RuntimeException('Architecture revalidation routing failed.');
}
$d = $coordinator->acceptAgentResult($workflow, AgentRole::PRINCIPAL_ARCHITECT, ['status' => 'APPROVED']);
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('Developer routing after architecture revalidation failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'REQUEST_CHANGES'], new WorkflowCounters(0, 1, 0));
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('Review fix routing failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters(1, 2, 0));
if ($d->agent !== AgentRole::QA_EXECUTOR || $workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) throw new RuntimeException('QA Executor routing failed.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA_EXECUTOR, ['status' => 'TESTS_UPDATED'], new WorkflowCounters(1, 2, 0));
if ($d->agent !== AgentRole::REVIEWER || $workflow->currentState() !== EngineeringWorkflowState::REVIEW_PENDING) throw new RuntimeException('QA test update did not require re-review.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters(1, 3, 0));
if ($d->agent !== AgentRole::QA_EXECUTOR || $workflow->currentState() !== EngineeringWorkflowState::QA_PENDING) throw new RuntimeException('QA Executor did not resume after test re-review.');

$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA_EXECUTOR, ['status' => 'FAIL'], new WorkflowCounters(1, 3, 1));
if ($d->agent !== AgentRole::DEVELOPER || $workflow->currentState() !== EngineeringWorkflowState::DEVELOPMENT_RUNNING) throw new RuntimeException('QA fix routing failed.');

$coordinator->acceptAgentResult($workflow, AgentRole::DEVELOPER, ['status' => 'COMPLETED']);
$coordinator->acceptAgentResult($workflow, AgentRole::REVIEWER, ['status' => 'APPROVED'], new WorkflowCounters(2, 4, 1));

$ready = new ReadyForHumanApprovalEvidence(
    architectureApproved: true,
    developmentCompleted: true,
    reviewApproved: true,
    qaPassed: true,
    ciPassed: true,
    allBlockingAcceptanceCriteriaVerified: true,
    hasOpenCriticalFinding: false,
    hasBlockingHumanDecision: false,
    hasRunningTask: false,
    tenantIsolationVerified: true,
    authorizationVerified: true,
    authenticationVerifiedOrNotApplicable: true,
    migrationVerifiedOrNotApplicable: true,
    rollbackVerifiedOrNotApplicable: true,
    apiCompatibilityVerifiedOrNotApplicable: true,
    staticAnalysisPassed: true,
    requiredTestsPassed: true,
    smokePassed: true,
    documentationImpactChecked: true,
    hasOpenMajorOrHigherFinding: false,
    revisionConsistent: true,
);
$d = $coordinator->acceptAgentResult($workflow, AgentRole::QA_EXECUTOR, ['status' => 'PASS'], new WorkflowCounters(2, 4, 2), $ready);

if ($d->type !== WorkflowDirectiveType::READY_FOR_HUMAN_APPROVAL || $workflow->currentState() !== EngineeringWorkflowState::READY_FOR_HUMAN_APPROVAL) {
    throw new RuntimeException('Golden workflow did not reach READY_FOR_HUMAN_APPROVAL.');
}

echo "Engineering golden workflow passed.\n";
