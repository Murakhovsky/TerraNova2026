<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$observer = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/GitHub/GitHubEngineeringTransitionObserver.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringWorkflowStore.php');
$intake = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringIssueIntakeService.php');
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');

foreach ([
    'SPECIFICATION_READY',
    'ARCHITECTURE_APPROVED',
    'REVIEW_PENDING',
    'CHANGES_REQUESTED',
    'QA_PENDING',
    'QA_FAILED',
    'READY_FOR_HUMAN_APPROVAL',
    'HUMAN_DECISION_REQUIRED',
] as $state) {
    if (!str_contains($observer, $state)) throw new RuntimeException('GitHub issue transition reporting missing '.$state);
}
if (!str_contains($store, 'observer->afterPersisted')) throw new RuntimeException('Workflow persistence does not emit transition observation.');
if (!str_contains($observer, 'catch (Throwable')) throw new RuntimeException('GitHub issue synchronization must not break persisted workflow transitions.');
if (!str_contains($intake, "'cos:engineering'")) throw new RuntimeException('Canonical engineering issue label is missing.');
if (!str_contains($gateway, 'Labels improve operations but must not block feature intake.')) throw new RuntimeException('Issue label mutation must be best-effort.');

echo "Engineering GitHub stage reporting passed.\n";
