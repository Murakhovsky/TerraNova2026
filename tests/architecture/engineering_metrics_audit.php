<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$metrics = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Metrics/DoctrineEngineeringMetricsProvider.php');
$audit = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Audit/DoctrineEngineeringAuditQuery.php');
$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');

foreach ([
    'features_started','features_completed','features_escalated','manager_analysis_success_rate',
    'average_agent_runs_per_feature','average_review_cycles','average_qa_cycles',
    'human_interventions','architect_runs','architecture_revalidations','architecture_human_decisions',
    'cost_per_feature','time_to_ready_seconds','failed_workflows',
] as $needle) {
    if (!str_contains($metrics, $needle)) throw new RuntimeException('Engineering metrics missing '.$needle);
}
foreach (['who','what','when','why','based_on','result'] as $needle) {
    if (!str_contains($audit, "'".$needle."'")) throw new RuntimeException('Engineering audit projection missing '.$needle);
}
foreach (['/api/engineering/metrics','/api/engineering/features/{id}/audit'] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Engineering observability API missing '.$needle);
}

echo "Engineering metrics and audit read models passed.\n";
