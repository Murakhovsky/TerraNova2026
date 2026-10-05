<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
$controller = (string) file_get_contents($root.'/symfony/src/Web/Engineering/EngineeringDomainWorkspaceController.php');
$index = (string) file_get_contents($root.'/symfony/templates/experience/engineering/domains/index.html.twig');
$show = (string) file_get_contents($root.'/symfony/templates/experience/engineering/domains/show.html.twig');
$scheduler = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainFeatureScheduler.php');
$runtime = (string) file_get_contents($root.'/symfony/src/Engineering/Application/DomainDevelopment/EngineeringDomainRuntimeService.php');

foreach ([
    '/admin/engineering/domains',
    '/admin/engineering/domains/create',
    '/admin/engineering/domains/{id}/plan',
    '/admin/engineering/domains/{id}/tick',
    '/admin/engineering/domains/{id}/verify',
    '/admin/engineering/domains/{id}/approve',
    'EngineeringDomainWorkspaceController::index',
    'EngineeringDomainWorkspaceController::show',
] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Engineering Domain workspace route missing '.$needle);
}

foreach ([
    'TenantPermissions::MANAGE',
    'EngineeringDomainRuntimeService',
    'runtime->create',
    'runtime->plan',
    'runtime->tick',
    'runtime->verify',
    'runtime->approveRelease',
    'start_now',
    'SystemControlSurface',
    'EntityWorkspace',
] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Engineering Domain workspace controller missing '.$needle);
}

foreach ([
    'Create Domain',
    'Master Specification',
    'Domain Initiatives',
    'data-cos-engineering-domain="index"',
] as $needle) {
    if (!str_contains($index, $needle)) throw new RuntimeException('Engineering Domain index UI missing '.$needle);
}

foreach ([
    'Domain overview',
    'Capabilities',
    'Feature execution map',
    'Dependency graph',
    'Public contracts',
    'Canonical Domain artifacts',
    'Domain agent executions',
    'Domain QA Plan',
    'Domain QA Report',
    'Integration &amp; Release',
    'Domain Release Manifest',
    'data-cos-engineering-domain="show"',
] as $needle) {
    if (!str_contains($show, $needle)) throw new RuntimeException('Engineering Domain workspace UI missing '.$needle);
}

// ER2-AC-024: user supplies one Domain specification; runtime creates leaf feature workflows itself.
foreach ([
    '$this->engineering->create(',
    '$this->engineering->queue(',
    'readyKeys',
    'max_parallel_features',
] as $needle) {
    if (!str_contains($scheduler, $needle)) throw new RuntimeException('ER2-AC-024 scheduler contract missing '.$needle);
}
foreach (['planner->plan', 'scheduler->tick'] as $needle) {
    if (!str_contains($runtime, $needle)) throw new RuntimeException('Domain Runtime orchestration missing '.$needle);
}

echo "Engineering Domain Workspace V2 and ER2-AC-024 contract passed.\n";
