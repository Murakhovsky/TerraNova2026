<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringIssueIntakeService.php');
$gateway = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Repository/GitHubEngineeringRepositoryGateway.php');

foreach (['issue(', 'commentIssue', 'addIssueLabels', "sourceType: 'github_issue'", "'cos-engineering'"] as $needle) {
    if (!str_contains($service.$gateway, $needle)) throw new RuntimeException('GitHub Issue intake missing '.$needle);
}

echo "Engineering GitHub Issue intake passed.\n";
