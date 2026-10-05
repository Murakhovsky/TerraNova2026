<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root.'/symfony/config/routes.yaml');
$controller = (string) file_get_contents($root.'/symfony/src/Web/Engineering/EngineeringFeatureController.php');
$template = (string) file_get_contents($root.'/symfony/templates/experience/engineering/feature.html.twig');
$indexTemplate = (string) file_get_contents($root.'/symfony/templates/experience/engineering/index.html.twig');
$status = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringStatusService.php');
$navigation = (string) file_get_contents($root.'/symfony/src/Web/Experience/Extension/ProviderBackedShellNavigation.php');
$commands = (string) file_get_contents($root.'/symfony/src/Web/Experience/Shell/CoreCommandCatalog.php');
$management = (string) file_get_contents($root.'/symfony/src/Engineering/Application/Service/EngineeringFeatureManagementService.php');
$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringFeatureStore.php');

foreach ([
    '/admin/engineering',
    '/admin/engineering/{id}',
    'EngineeringFeatureController::index',
    'EngineeringFeatureController::show',
    '/admin/engineering/{id}/update',
    '/admin/engineering/{id}/delete',
    '/admin/engineering/{id}/cancel',
    '/admin/engineering/{id}/finalize',
    '/admin/engineering/{id}/queue',
    'EngineeringFeatureController::queue',
    'EngineeringFeatureController::update',
    'EngineeringFeatureController::delete',
    'EngineeringFeatureController::cancel',
    'EngineeringFeatureController::finalize',
] as $needle) {
    if (!str_contains($routes, $needle)) throw new RuntimeException('Engineering feature UI route missing '.$needle);
}

foreach ([
    'TenantPermissions::MANAGE',
    'organization_id',
    'EngineeringStatusService',
    'recentForOrganization',
    'SystemControlSurface',
    'EngineeringFeatureManagementService',
    'EngineeringCancelService',
    'EngineeringFinalizeService',
    'uiActions',
    'ContinueEngineeringWorkflowsCommand',
    'RunEngineeringFeatureCommand',
    'queueForOrganization',
    'activeForOrganization',
    'workspaceRows',
    'workspaceStats',
    'workflow_state',
    'workspaceFactsForFeatures',
    'runtimeHealth',
    'workspaceView',
    'workspaceQuery',
    'queueImmediate',
] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Engineering feature UI controller missing '.$needle);
}

foreach ([
    'Термінал виконання',
    'План виконання',
    'Запуски агентів',
    'Облік LLM',
    'Контроль якості',
    'LLM ще не запускався',
    'Не вдалося прив’язати використання LLM',
    'Токени обліковані, вартість ще не визначена',
    'Вимоги та опис',
    'engineering.timeline',
    'runtime_steps',
    'Вхідний контекст агента',
    'Кроки runtime',
    'heartbeat_at',
    'health_status',
    'Інструменти та дії',
    'engineering.execution_events',
    'engineering-details-tabs',
    'data-tabs-target="panel"',
    'data-engineering-update',
    'Перевірити merge та завершити',
    'data-engineering-delete',
] as $needle) {
    if (!str_contains($template, $needle)) throw new RuntimeException('Engineering workflow terminal UI missing '.$needle);
}

foreach ([
    'Агентська розробка',
    'Процеси',
    'P0 → P1 → P2 → P3',
    'data-engineering-workspace-list',
    'execution_mode',
    'Фільтри Engineering',
    'name="q"',
    'облік недоступний',
    'Потрібне рішення',
    'Повторити',
] as $needle) {
    if (!str_contains($indexTemplate, $needle)) throw new RuntimeException('Engineering operational workspace UI missing '.$needle);
}

foreach (['latestIdForFeature', 'Started Engineering workflow is immutable', 'updateRequest', 'delete'] as $needle) {
    if (!str_contains($management, $needle)) throw new RuntimeException('Engineering feature management guard missing '.$needle);
}
foreach (["'description' =>", 'updateRequest(', 'entityManager->remove'] as $needle) {
    if (!str_contains($store, $needle)) throw new RuntimeException('Engineering feature persistence missing '.$needle);
}

if (!str_contains($status, 'latestIdForFeature')) {
    throw new RuntimeException('Engineering feature UI cannot retain terminal workflow state.');
}
if (!str_contains($navigation, "'engineering'") || !str_contains($navigation, "'/admin/engineering'")) {
    throw new RuntimeException('Engineering workspace navigation entry missing.');
}
if (!str_contains($commands, "'core.engineering'")) {
    throw new RuntimeException('Engineering command palette entry missing.');
}

echo "Engineering feature UI contract passed.\n";
