<?php
declare(strict_types=1);

/**
 * Prevent accidental reopening of the execution authorization gap.
 * Runtime smoke verifies real MySQL rows separately.
 */
$root = dirname(__DIR__, 2);
$store = (string) file_get_contents($root . '/symfony/src/Persistence/Federation/FederationGoalStore.php');
$preflight = (string) file_get_contents($root . '/symfony/src/Persistence/Federation/FederationWorkflowPreflight.php');
$reader = (string) file_get_contents($root . '/symfony/src/Persistence/Federation/FederationPlanApprovalEvidenceReader.php');
$migration = (string) file_get_contents($root . '/symfony/migrations/Version20261008193000.php');
$factory = (string) file_get_contents($root . '/app/Platform/Orchestration/Goal/GoalPlanApprovalRequestFactory.php');
$docker = (string) file_get_contents($root . '/docker/symfony/php/Dockerfile');
$receipts = (string) file_get_contents($root . '/symfony/src/Persistence/Federation/FederationExternalActionReceiptReconciler.php');
foreach ([
    [$store, 'private FederationPlanApprovalEvidenceReader $approvalEvidence'],
    [$store, '$this->approvalEvidence->requireApproval('],
    [$store, 'Plan already has an execution run; replay is blocked.'],
    [$preflight, '$this->approvalEvidence->requireApproval('],
    [$preflight, "'approved_capabilities'"],
    [$reader, 'cos_policy_evaluations'],
    [$reader, 'cos_approvals'],
    [$reader, 'hash_equals('],
    [$reader, 'decided_by_type'],
    [$migration, 'uq_federation_plan_run'],
    [$factory, "'APPROVAL_REQUIRED'"],
    [$factory, "'cos.federation.plan.approval'"],
    [$docker, 'COPY resources/contracts/ /var/www/html/resources/contracts/'],
    [$receipts, 'assertCompletedReceipt('],
] as [$content, $marker]) {
    if (!str_contains($content, $marker)) {
        throw new RuntimeException('Federation governance invariant missing: ' . $marker);
    }
}
foreach (['->submit(', '->queue(', '->execute('] as $unsafe) {
    if (str_contains($factory, $unsafe)) {
        throw new RuntimeException('Federation approval factory may not dispatch Actions: ' . $unsafe);
    }
}
echo "Federation Approval/Execution authorization contracts passed.\n";
