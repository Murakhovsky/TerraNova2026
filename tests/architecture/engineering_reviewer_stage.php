<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$stage = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReviewerStageExecutor.php');
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');
$coordinator = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Workflow/EngineeringWorkflowCoordinator.php');
$workflow = (string) file_get_contents($root.'/symfony/src/Engineering/Domain/Workflow/EngineeringWorkflowDefinition.php');
$prompt = (string) file_get_contents($root.'/symfony/config/engineering/prompts/reviewer-v0.1.md');

foreach (['pullRequestFiles','pullRequest(','head_revision','commitChecks','ArtifactType::REVIEW_REPORT','ArtifactType::IMPLEMENTATION_PLAN','ArtifactType::DEVELOPER_HANDOFF',"'implementation_plan'","'ci_results'","'coding_standards'","'security_standards'","'issues'",'REVIEW_PENDING','reviewed_revision','assertAcceptanceCriteriaCoverage','WorkflowCounters'] as $needle) {
    if (!str_contains($stage.$gateway, $needle)) throw new RuntimeException('Reviewer stage missing '.$needle);
}
foreach (['REQUEST_CHANGES','ARCHITECTURE_REVIEW_REQUIRED','HUMAN_REVIEW_REQUIRED','AgentRole::PRINCIPAL_ARCHITECT','AgentRole::DEVELOPER','AgentRole::QA'] as $needle) {
    if (!str_contains($coordinator, $needle)) throw new RuntimeException('Reviewer coordinator routing missing '.$needle);
}
if (!str_contains($workflow, "'REVIEW_PENDING' => [EngineeringWorkflowState::QA_PENDING, EngineeringWorkflowState::ARCHITECTURE_PENDING")) throw new RuntimeException('Reviewer architecture revalidation transition is missing.');
foreach (['Do not modify production implementation.','Do not merge the PR.','BLOCKER','MAJOR','MINOR','SUGGESTION'] as $needle) {
    if (!str_contains($prompt, $needle)) throw new RuntimeException('Reviewer independence contract missing '.$needle);
}

echo "Engineering Reviewer stage passed.\n";
