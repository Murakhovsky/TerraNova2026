<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');

foreach (['pullRequestFiles', 'ArtifactType::REVIEW_REPORT', 'REVIEW_PENDING', 'reviewed_revision', 'WorkflowCounters'] as $needle) {
    if (!str_contains($stage.$gateway, $needle)) throw new RuntimeException('Reviewer stage missing '.$needle);
}

echo "Engineering Reviewer stage passed.\n";
