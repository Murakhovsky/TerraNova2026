<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifestPath = $root . '/tests/architecture/contracts/web_platform_definition_of_done.php';

if (!is_file($manifestPath)) {
    throw new RuntimeException('Wave 12 Definition of Done evidence manifest is missing.');
}

/** @var array<string,list<string>> $evidence */
$evidence = require $manifestPath;

$expectedCategories = [
    'Architecture',
    'Foundation',
    'UI Runtime',
    'Platform',
    'Realtime',
    'AI',
    'Workflow',
    'Mobile',
    'PWA',
    'Native-ready',
    'Security',
    'Quality',
    'Operations',
    'Reference Vertical',
];

if (array_keys($evidence) !== $expectedCategories) {
    throw new RuntimeException(
        'Wave 12 Definition of Done categories drifted. Expected: '
        . implode(', ', $expectedCategories),
    );
}

$allEvidence = [];
foreach ($evidence as $category => $paths) {
    if ($paths === []) {
        throw new RuntimeException('Definition of Done category has no executable evidence: ' . $category);
    }

    foreach ($paths as $relative) {
        if (!is_string($relative) || $relative === '' || str_starts_with($relative, '/')) {
            throw new RuntimeException('Invalid Definition of Done evidence path in ' . $category);
        }

        if (!is_file($root . '/' . $relative)) {
            throw new RuntimeException(sprintf(
                'Definition of Done evidence is missing for %s: %s',
                $category,
                $relative,
            ));
        }

        $allEvidence[$relative] = true;
    }
}

$diagnostic = (string) file_get_contents($root . '/.github/workflows/diagnostic.yml');
$symfony = (string) file_get_contents($root . '/.github/workflows/symfony-bootstrap.yml');
$workflow = (string) file_get_contents($root . '/.github/workflows/kernel-workflow.yml');
$sales = (string) file_get_contents($root . '/.github/workflows/sales.yml');
$ci = $diagnostic . "
" . $symfony . "
" . $workflow . "
" . $sales;

$criticalCiEvidence = [
    'tests/architecture/web_experience_wave12_foundation.php',
    'tests/architecture/web_experience_wave12_runtime.php',
    'tests/architecture/web_experience_wave12_extensions.php',
    'tests/architecture/web_experience_wave12_actions.php',
    'tests/architecture/web_experience_wave12_realtime.php',
    'tests/architecture/web_experience_wave12_async_operations.php',
    'tests/architecture/web_experience_wave12_ai_ui.php',
    'tests/architecture/web_experience_wave12_mobile.php',
    'tests/architecture/web_experience_wave12_pwa.php',
    'tests/architecture/web_experience_wave12_native.php',
    'tests/architecture/web_experience_wave12_security.php',
    'tests/architecture/web_experience_wave12_testing.php',
    'tests/architecture/web_experience_wave12_performance_observability.php',
    'tests/architecture/web_experience_wave12_sales_cutover.php',
    'tests/architecture/web_platform_v1_freeze.php',
    'tests/unit/kernel_workflow_engine.php',
    'symfony/tests/workflow_state_manager_contract.php',
    'tests/browser/web_platform_quality.mjs',
    'tests/browser/web_performance_budget.mjs',
    'tests/browser/sales_workspace.mjs',
];

foreach ($criticalCiEvidence as $relative) {
    if (!isset($allEvidence[$relative]) && !str_contains($relative, 'web_platform_v1_freeze.php')) {
        throw new RuntimeException('Critical CI evidence is not represented in DoD manifest: ' . $relative);
    }

    if (!str_contains($ci, $relative)) {
        throw new RuntimeException('Definition of Done evidence is not wired into CI: ' . $relative);
    }
}

$adr = (string) file_get_contents($root . '/docs/11-decisions/ADR-0011-web-platform-v1-freeze.md');
if (!str_contains($adr, 'Web Experience Platform v1 заморожується')) {
    throw new RuntimeException('Definition of Done requires the Web Platform v1 architecture freeze.');
}

$routes = (string) file_get_contents($root . '/symfony/config/routes.yaml');
foreach ([
    'controller: App\\Web\\Sales\\SalesWorkspaceController::dashboard',
    'controller: App\\Web\\Sales\\SalesWorkspaceController::leads',
    'controller: App\\Web\\Sales\\SalesWorkspaceController::lead',
] as $marker) {
    if (!str_contains($routes, $marker)) {
        throw new RuntimeException('Reference Vertical is not cut over to canonical Sales UI: ' . $marker);
    }
}

foreach ([
    'app/Interfaces/Web/View/sales/dashboard.phtml',
    'app/Interfaces/Web/View/sales/leads.phtml',
    'symfony/src/Web/Sales/SalesReferenceController.php',
] as $retired) {
    if (file_exists($root . '/' . $retired)) {
        throw new RuntimeException('Definition of Done requires retired Sales UI ownership to stay deleted: ' . $retired);
    }
}

echo sprintf(
    "Wave 12 Definition of Done passed: %d categories / %d evidence artifacts.\n",
    count($evidence),
    count($allEvidence),
);
