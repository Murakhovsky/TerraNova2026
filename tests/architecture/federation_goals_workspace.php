<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/symfony/src/Web/Federation/GoalsWorkspaceController.php');
$template = (string) file_get_contents($root . '/symfony/templates/experience/federation/goals.html.twig');
$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_federation_goals_index', 'cos_federation_goals_create', 'cos_federation_goals_mode'] as $route) {
    if (!str_contains($routes, $route)) throw new RuntimeException('Goal Workspace route is missing: ' . $route);
}
foreach (['$this->tenants->current()', 'TenantPermissions::MANAGE',
    '$this->csrf->isValid($request)', '$this->goals->createGoal(', '$this->experience->saveWorkspace('] as $required) {
    if (!str_contains($controller, $required)) throw new RuntimeException('Goal Workspace security/persistence contract missing: ' . $required);
}
foreach (['data-experience-mode', 'Результат', 'Процес', 'Експерт',
    'data-goal-process', 'data-goal-expert', 'data-goal-id',
    'csrf_token'] as $required) {
    if (!str_contains($template, $required)) throw new RuntimeException('Goal Workspace template missing: ' . $required);
}
echo "Federation Goal Workspace mode, tenant and CSRF contracts passed.\n";
