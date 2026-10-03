<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
$controller = (string) file_get_contents($root.'/symfony/src/Web/Engineering/EngineeringFeatureController.php');
$template = (string) file_get_contents($root.'/symfony/templates/experience/engineering/feature.html.twig');
$status = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');

foreach ([
    '/admin/engineering',
    '/admin/engineering/{id}',
    'EngineeringFeatureController::index',
    'EngineeringFeatureController::show',
] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Engineering feature UI route missing '.$needle);
}

foreach ([
    'TenantPermissions::MANAGE',
    'organization_id',
    'EngineeringStatusService',
    'recentForOrganization',
    'SystemControlSurface',
] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Engineering feature UI controller missing '.$needle);
}

foreach ([
    'current stage',
    'Open findings',
    'Tasks',
    'AgentRuns',
    'Final Report',
    'Open pull request',
] as $needle) {
    if (!str_contains($template, $needle)) throw new RuntimeException('Engineering feature UI missing '.$needle);
}

if (!str_contains($status, 'latestIdForFeature')) {
    throw new RuntimeException('Engineering feature UI cannot retain terminal workflow state.');
}

echo "Engineering feature UI contract passed.\n";
