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
// Native UI must never dispatch legacy technical Runs or fake human Approvals.
foreach ([
    'cos_federation_goals_build_plan',
    'cos_federation_goals_request_approval',
    'cos_federation_goals_start_plan',
    'cos_federation_goals_advance_run',
] as $route) {
    if (!str_contains($routes,$route)) throw new RuntimeException('Goal operator route missing: '.$route);
}
foreach ([
    '$this->smallPlans->propose(',
    '$this->discoveryPlans->propose(',
    '$this->planApprovals->requestApproval(',
    '$this->canonical->start(',
    '$this->canonical->advance(',
] as $contract) {
    if (!str_contains($controller,$contract)) throw new RuntimeException('Goal canonical execution contract missing: '.$contract);
}
foreach ([
    'Побудувати план',
    'Запросити незалежне погодження',
    'Запустити через Platform Workflow',
    'data-goal-progress',
    'data-goal-blocker',
] as $contract) {
    if (!str_contains($template,$contract)) throw new RuntimeException('Goal operator UX contract missing: '.$contract);
}
if (str_contains($controller,'$this->goals->startApprovedRun(')
    || str_contains($controller,'FederationSequentialOrchestrator')) {
    throw new RuntimeException('Goal UI cannot execute via retired Federation authority.');
}

echo "Federation Goal Workspace mode, tenant and CSRF contracts passed.\n";
