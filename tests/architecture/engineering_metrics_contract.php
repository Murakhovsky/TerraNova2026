<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$provider = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Metrics/DoctrineEngineeringMetricsProvider.php');
$report = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringReportBuilder.php');

foreach ([
    'task_success_rate',
    'first_pass_success_rate',
    'review_rejection_rate',
    'qa_rejection_rate',
    'human_interventions_per_task',
    'cost_per_completed_feature',
] as $needle) {
    if (!str_contains($provider, $needle)) throw new RuntimeException('Engineering metrics provider missing '.$needle);
}

foreach ([
    'first_pass_success',
    'development_attempts',
    'architecture_revalidations',
    'technical_retries',
    'defects_found_review',
    'defects_found_qa',
    'time_to_pr_seconds',
    'time_to_ready_seconds',
    'classification',
] as $needle) {
    if (!str_contains($report, $needle)) throw new RuntimeException('Engineering final report metrics missing '.$needle);
}

echo "Engineering metrics contract passed.\n";
