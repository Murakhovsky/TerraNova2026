<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Sales/SalesAdminDashboardController.php',
    'symfony/src/Web/Sales/SalesAdminDashboardPresenter.php',
    'symfony/src/Web/Sales/ViewModel/SalesAdminDashboardViewModel.php',
    'symfony/templates/experience/sales/admin/dashboard.html.twig',
] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('VR-009 admin foundation artifact is missing: ' . $relative);
    }
}

if (is_file($root . '/app/Interfaces/Web/View/sales/admin.phtml')) {
    throw new RuntimeException('VR-009 canonical Sales Admin dashboard must not retain legacy PHTML ownership.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
if (!str_contains($routes, 'SalesAdminDashboardController::index')) {
    throw new RuntimeException('Sales Admin dashboard route has not moved to the canonical controller.');
}

$query = (string) file_get_contents($root . '/symfony/src/Application/Sales/Admin/SalesAdminQueryHandler.php');
foreach ([
    "'dashboard'",
    "'integration.list'",
    "'integration.catalog'",
    "'integration.routing_options'",
    "'health.dashboard'",
    'SalesIntegrationAdministrationInterface',
    'SalesAdministrationReadModelInterface',
    'SalesWorkspaceOperationalReadModelInterface',
] as $marker) {
    if (!str_contains($query, $marker)) {
        throw new RuntimeException('Sales Admin canonical read boundary is incomplete: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesAdminDashboardController.php');
foreach ([
    'SalesAdminQuery',
    "new SalesAdminQuery(\$tenant->organizationId(), 'dashboard')",
    'PageArchetype::SystemControlSurface',
    'WorkspaceShellFactory',
    "'Toolbar'",
    "'KpiStrip'",
] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Sales Admin dashboard controller contract is incomplete: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/sales/admin/dashboard.html.twig');
foreach ([
    '<twig:CosPageHeader',
    '<twig:CosToolbar',
    'class="cos-kpi-strip"',
    '<twig:CosMetric',
    '<twig:CosActionBar',
    'data-cos-archetype',
] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Sales Admin control surface composition is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($template, $forbidden)) {
        throw new RuntimeException('Sales Admin dashboard restored legacy/local presentation: ' . $forbidden);
    }
}


$controlController = (string) file_get_contents($root . '/symfony/src/Web/Sales/SalesAdminControlController.php');
foreach ([
    "'page.pipelines'",
    "'page.rules'",
    "'page.agents'",
    "'page.actions'",
    "'page.teams'",
    "'page.integrations'",
    "'page.health'",
    'SalesAdminAuthorization::TEAMS',
    'SalesAdminAuthorization::INTEGRATIONS',
    'SalesAdminAuthorization::AUDIT',
] as $marker) {
    if (!str_contains($controlController, $marker)) {
        throw new RuntimeException('VR-009 control surface controller is incomplete: ' . $marker);
    }
}

foreach ([
    'pipelines' => ['sales-admin-pipelines', 'data-action="submit->sales-admin-pipelines#create"'],
    'rules' => ['sales-admin-rules', 'data-action="submit->sales-admin-rules#create"'],
    'agents' => ['Sales Intelligence Agents', '<twig:CosEntityListItem'],
    'actions' => ['sales-admin-policies', 'data-action="submit->sales-admin-policies#create"'],
    'teams' => ['sales-admin-teams', 'data-action="submit->sales-admin-teams#membership"'],
    'integrations' => ['sales-admin-integrations', 'data-action="submit->sales-admin-integrations#update"'],
    'health' => ['class="cos-kpi-strip"', 'Operational queue'],
] as $surface => $markers) {
    $templatePath = $root . '/symfony/templates/experience/sales/admin/' . $surface . '.html.twig';
    if (!is_file($templatePath)) {
        throw new RuntimeException('VR-009 control surface template is missing: ' . $surface);
    }
    $source = (string) file_get_contents($templatePath);
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('VR-009 ' . $surface . ' lost behavior/composition marker: ' . $marker);
        }
    }
    foreach (['tn-', 'style=', '<script'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('VR-009 ' . $surface . ' restored legacy presentation: ' . $forbidden);
        }
    }
}

foreach ([
    'app/Interfaces/Web/View/sales_admin/pipelines.phtml',
    'app/Interfaces/Web/View/sales_admin/rules.phtml',
    'app/Interfaces/Web/View/sales_admin/agents.phtml',
    'app/Interfaces/Web/View/sales_admin/actions.phtml',
    'app/Interfaces/Web/View/sales_admin/teams.phtml',
    'app/Interfaces/Web/View/sales_admin/integrations.phtml',
    'app/Interfaces/Web/View/sales_admin/health.phtml',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-009 migrated control PHTML returned: ' . $legacy);
    }
}

foreach ([
    'pipeline' => ['sales-admin-pipeline', 'data-action="submit->sales-admin-pipeline#transitions"'],
    'agent' => ['sales-admin-agent', 'data-action="submit->sales-admin-agent#save"'],
] as $surface => $markers) {
    $templatePath = $root . '/symfony/templates/experience/sales/admin/' . $surface . '.html.twig';
    if (!is_file($templatePath)) {
        throw new RuntimeException('VR-009 detail surface template is missing: ' . $surface);
    }
    $source = (string) file_get_contents($templatePath);
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            throw new RuntimeException('VR-009 ' . $surface . ' lost editor behavior: ' . $marker);
        }
    }
    foreach (['tn-', 'style=', '<script'] as $forbidden) {
        if (str_contains($source, $forbidden)) {
            throw new RuntimeException('VR-009 ' . $surface . ' restored legacy presentation: ' . $forbidden);
        }
    }
}

foreach ([
    'app/Interfaces/Web/View/sales_admin/pipeline.phtml',
    'app/Interfaces/Web/View/sales_admin/agent.phtml',
    'frontend/features/sales/admin.js',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-009 migrated detail/admin runtime returned: ' . $legacy);
    }
}

$ruleTemplate = (string) file_get_contents($root . '/symfony/templates/experience/sales/admin/rule.html.twig');
foreach ([
    'data-controller="sales-admin-rule-editor"',
    'sales-admin-rule-editor#save',
    'sales-admin-rule-editor#dryRun',
    'sales-admin-rule-editor#lifecycle',
    'data-sales-admin-rule-editor-catalog-value',
    'data-sales-admin-rule-editor-current-value',
] as $marker) {
    if (!str_contains($ruleTemplate, $marker)) {
        throw new RuntimeException('VR-009 Rule editor contract is incomplete: ' . $marker);
    }
}
foreach (['tn-', 'style=', '<script'] as $forbidden) {
    if (str_contains($ruleTemplate, $forbidden)) {
        throw new RuntimeException('VR-009 Rule editor restored legacy presentation: ' . $forbidden);
    }
}

$ruleRuntime = (string) file_get_contents($root . '/symfony/assets/controllers/sales_admin_rule_editor_controller.js');
foreach ([
    '/api/v1/sales/admin/rules/',
    'conditionRow',
    'actionRow',
    'dryRun',
    'restore-system',
] as $marker) {
    if (!str_contains($ruleRuntime, $marker)) {
        throw new RuntimeException('VR-009 Rule Stimulus runtime is incomplete: ' . $marker);
    }
}

foreach ([
    'symfony/src/Web/Sales/SalesAdminPageController.php',
    'frontend/entrypoints/sales-workspace.js',
    'frontend/features/sales/workspace.js',
    'frontend/features/sales/workspace.css',
    'frontend/features/sales/rule-editor.js',
    'frontend/features/sales/rule-editor.css',
    'app/Interfaces/Web/View/components/sales/navigation.phtml',
] as $legacy) {
    if (is_file($root . '/' . $legacy)) {
        throw new RuntimeException('VR-009 final legacy Sales presentation returned: ' . $legacy);
    }
}

$views = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/app/Interfaces/Web/View', FilesystemIterator::SKIP_DOTS)
);
foreach ($views as $view) {
    if (!$view->isFile() || strtolower($view->getExtension()) !== 'phtml') continue;
    $relative = str_replace('\\', '/', substr($view->getPathname(), strlen($root) + 1));
    if (str_contains($relative, '/sales/') || str_contains($relative, '/sales_admin/')) {
        throw new RuntimeException('VR-009 final Sales PHTML burn-down failed: ' . $relative);
    }
}

echo "Wave 13 VR-009 Sales Admin complete: canonical Twig/Stimulus, Sales PHTML = 0.\n";
