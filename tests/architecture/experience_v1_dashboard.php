<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

foreach ([
    'symfony/src/Web/Experience/Dev/ExperienceDashboardController.php',
    'symfony/templates/experience/dev/experience_dashboard.html.twig',
    'resources/experience/pages/core/experience-dashboard.yaml',
] as $file) {
    if (!is_file($root . '/' . $file)) {
        throw new RuntimeException('Experience Dashboard artifact missing: ' . $file);
    }
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach (['cos_web_experience_dashboard:', 'path: /admin/experience', 'ExperienceDashboardController'] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Experience Dashboard route contract missing: ' . $marker);
    }
}

$contract = (string) file_get_contents($root . '/resources/experience/pages/core/experience-dashboard.yaml');
foreach (['core.experience.dashboard', 'priority: P0', 'status: IMPLEMENTED', 'system_control_surface'] as $marker) {
    if (!str_contains($contract, $marker)) {
        throw new RuntimeException('Experience Dashboard Page Contract missing marker: ' . $marker);
    }
}

$controller = (string) file_get_contents($root . '/symfony/src/Web/Experience/Dev/ExperienceDashboardController.php');
foreach (['ExperienceRouteInventory', 'CompiledPageContractRegistry', 'DesignSystemAuditService', 'WorkspaceShellFactory'] as $marker) {
    if (!str_contains($controller, $marker)) {
        throw new RuntimeException('Experience Dashboard missing governance source: ' . $marker);
    }
}

$template = (string) file_get_contents($root . '/symfony/templates/experience/dev/experience_dashboard.html.twig');
foreach (['COS Experience V1', 'Domain readiness', 'Design System V1', 'Registered pages'] as $marker) {
    if (!str_contains($template, $marker)) {
        throw new RuntimeException('Experience Dashboard missing section: ' . $marker);
    }
}

echo "Experience Dashboard contract OK\n";
