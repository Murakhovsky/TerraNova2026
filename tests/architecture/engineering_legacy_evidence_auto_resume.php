<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$load = static fn (string $relative): string => (string) file_get_contents($root.'/'.$relative);
$resolver = $load('symfony/src/Engineering/Application/Service/EngineeringLegacyEvidenceGateReconciler.php');
$stage = $load('symfony/src/Engineering/Application/Service/EngineeringArchitectStageExecutor.php');
$continue = $load('symfony/src/Engineering/Application/Service/EngineeringContinueService.php');
$queue = $load('symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$coordinator = $load('symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$entity = $load('symfony/src/Persistence/Doctrine/Entity/Engineering/HumanDecisionRequestRecord.php');
$store = $load('symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringHumanDecisionStore.php');
foreach ([
    'isLegacyReadOnlyRefresh',
    'currentBaseRevision',
    'compareRevisions',
    'existingPathsAtRevision',
    'count($open) !== 1',
    'AgentRole::PRINCIPAL_ARCHITECT',
    'autoResolveReadOnlyEvidence',
    'resumeAfterAutomaticEvidenceRefresh',
] as $needle) {
    if (!str_contains($resolver, $needle)) throw new RuntimeException('Legacy evidence resolver missing '.$needle);
}
foreach ([
    'manager.legacy_evidence_gate_reclassified',
    'NEEDS_REPOSITORY_EVIDENCE',
    'legacyRefreshPaths',
    'manager.repository_evidence_auto_authorized',
] as $needle) {
    if (!str_contains($stage, $needle)) throw new RuntimeException('Architect compatibility evidence missing '.$needle);
}
if (!str_contains($continue, 'legacyEvidenceGates->reconcile')) throw new RuntimeException('Continue does not reclassify evidence gates.');
if (!str_contains($queue, "d.type = 'WORKFLOW_EVIDENCE_REFRESH'")) throw new RuntimeException('Existing evidence gates will not be scheduled.');
if (!str_contains($coordinator, "initiatedByType: 'SYSTEM'") || !str_contains($coordinator, 'REPOSITORY_EVIDENCE_AUTO_AUTHORIZED')) {
    throw new RuntimeException('Read-only evidence resumed by a fake human actor.');
}
if (!str_contains($entity, "'AUTO_RESOLVED'") || !str_contains($store, 'markAutoResolved(')) {
    throw new RuntimeException('Misclassified request still requires a human answer.');
}

echo "Legacy read-only evidence auto-resume contract passed.\n";
