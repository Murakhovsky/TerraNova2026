<?php
declare(strict_types=1);

/**
 * Canonical Goal single-writer architecture gate.
 * Source/DI regression only; real Action admission MySQL evidence is exercised
 * separately in tests/integration/workflow_action_claim_admission_mysql.php.
 */
$root=dirname(__DIR__,2);
$bridge=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationCanonicalWorkflowBridge.php');
$goals=(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationGoalStore.php');
$controller=(string)file_get_contents($root.'/symfony/src/Web/Federation/FederationRunController.php');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
$claim=(string)file_get_contents($root.'/symfony/src/Persistence/Workflow/DoctrineWorkflowActionClaimAdmission.php');

foreach ([
    [$bridge,"COS_FEDERATION_CANONICAL_ORGANIZATION"],
    [$bridge,"SELECT run_id FROM cos_federation_runs"],
    [$bridge,"source_plan_id=:plan"],
    [$bridge,"FROM cos_workflow_runs"],
    [$bridge,"FOR UPDATE"],
    [$bridge,"WorkflowExecutionStoreInterface"],
    [$bridge,"WorkflowStepClaim"],
    [$bridge,"WorkflowStepCheckpoint"],
    [$bridge,"WorkflowStepScheduler"],
    [$bridge,"'wf:'.$stepKey"],
    [$bridge,"ActionStatus::PendingApproval"],
    [$bridge,"manual_reconciliation_required"],
    [$bridge,"WorkflowStatus::COMPLETED"],
    [$goals,"Canonical Workflow already owns this Plan"],
    [(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationSequentialOrchestrator.php'),"Dual legacy/canonical execution ownership"],
    [(string)file_get_contents($root.'/symfony/src/Persistence/Federation/FederationExternalActionSubmission.php'),"legacy Action submission blocked"],
    [$bridge,"Dual canonical/legacy execution ownership"],
    [$goals,"UNION ALL"],
    [$controller,"newRunsEnabled("],
    [$controller,"->owns("],
    [$claim,"'PENDING_APPROVAL','QUEUED'"],
    [$claim,"'status'] !== 'PENDING'"],
    [$services,"alias: App\\Persistence\\Workflow\\DoctrineWorkflowActionWorkerAdmission"],
    [$services,"$"."admission: '@App\\Persistence\\Workflow\\DoctrineWorkflowActionClaimAdmission'"],
    [$services,"$"."receipts: '@App\\Persistence\\Workflow\\DoctrineWorkflowActionReceiptVerifier'"],
] as [$source,$marker]) {
    if (!str_contains($source,$marker)) {
        throw new RuntimeException('Canonical Federation single-writer contract missing: '.$marker);
    }
}
// No new general Run/Step DB writer is permitted in the Goal application.
foreach ([
    '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+cos_(?:workflow|federation)_(?:runs|steps)\b/i',
    '/->(?:insert|update|delete)\s*\(\s*["\\x27]cos_(?:workflow|federation)_(?:runs|steps)/i',
] as $pattern) {
    if (preg_match($pattern,$bridge)) {
        throw new RuntimeException('Goal adapter must delegate ALL technical writes to Platform Workflow.');
    }
}
if (!is_file($root.'/tests/integration/workflow_action_claim_admission_mysql.php')) {
    throw new RuntimeException('Canonical Action claim MySQL acceptance missing.');
}
echo "Canonical Goal single-writer source guard passed; tenant rollout remains OFF by default.\n";
