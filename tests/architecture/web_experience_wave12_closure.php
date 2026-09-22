<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$requiredGates = [
    'tests/architecture/web_experience_wave12_runtime.php',
    'tests/architecture/web_experience_wave12_toolkit.php',
    'tests/architecture/web_experience_wave12_design_system.php',
    'tests/architecture/web_experience_wave12_shell.php',
    'tests/architecture/web_experience_wave12_extensions.php',
    'tests/architecture/web_experience_wave12_actions.php',
    'tests/architecture/web_experience_wave12_interactions.php',
    'tests/architecture/web_experience_wave12_forms.php',
    'tests/architecture/web_experience_wave12_data.php',
    'tests/architecture/web_experience_wave12_workspace.php',
    'tests/architecture/web_experience_wave12_runtime_actions.php',
    'tests/architecture/web_experience_wave12_realtime.php',
    'tests/architecture/web_experience_wave12_async_operations.php',
    'tests/architecture/web_experience_wave12_ai_ui.php',
    'tests/architecture/web_experience_wave12_search.php',
    'tests/architecture/web_experience_wave12_mobile.php',
    'tests/architecture/web_experience_wave12_pwa.php',
    'tests/architecture/web_experience_wave12_native.php',
    'tests/architecture/web_experience_wave12_security.php',
    'tests/architecture/web_experience_wave12_feature_flags.php',
    'tests/architecture/web_experience_wave12_audit_history.php',
    'tests/architecture/web_experience_wave12_ui_catalog.php',
    'tests/architecture/web_experience_wave12_testing.php',
    'tests/architecture/web_experience_wave12_performance_observability.php',
    'tests/architecture/web_experience_wave12_sales_reference.php',
    'tests/architecture/web_experience_wave12_sales_cutover.php',
];

foreach ($requiredGates as $gate) {
    if (!is_file($root . '/' . $gate)) {
        throw new RuntimeException('Wave 12 final acceptance artifact is missing: ' . $gate);
    }
}

$workflow = (string) file_get_contents($root . '/.github/workflows/symfony-bootstrap.yml');
foreach ([
    'Wave 12.23 Panther E2E',
    'symfony/panther:2.4.0',
    'WebExperiencePantherTest.php',
    'Wave 12 final acceptance gate',
] as $marker) {
    if (!str_contains($workflow, $marker)) {
        throw new RuntimeException('Wave 12 CI closure marker is missing: ' . $marker);
    }
}

$panther = (string) file_get_contents($root . '/symfony/tests/Panther/WebExperiencePantherTest.php');
foreach ([
    'extends PantherTestCase',
    "'external_base_uri' => \\$baseUri",
    "request('GET', '/auth/login')",
    "request('GET', '/property/catalog')",
    "request('GET', '/dev/ui')",
] as $marker) {
    if (!str_contains($panther, $marker)) {
        throw new RuntimeException('Wave 12 Panther contract is incomplete: ' . $marker);
    }
}
if (str_contains($panther, 'if (class_exists(PantherTestCase::class))')) {
    throw new RuntimeException('Panther suite must be executable, not readiness-only guarded.');
}

$freeze = (string) file_get_contents($root . '/docs/11-decisions/ADR-0011-web-platform-v1-freeze.md');
foreach (['status: accepted', 'Web Experience Platform v1', 'Wave 12.26'] as $marker) {
    if (!str_contains($freeze, $marker)) {
        throw new RuntimeException('Web Platform v1 freeze evidence is incomplete: ' . $marker);
    }
}

$cutover = (string) file_get_contents($root . '/docs/03-architecture/sales-cutover.md');
foreach (['Wave 12.26 завершує production cutover', '/sales/dashboard', '/sales/leads/{id}'] as $marker) {
    if (!str_contains($cutover, $marker)) {
        throw new RuntimeException('Wave 12.26 cutover evidence is incomplete: ' . $marker);
    }
}

$closure = (string) file_get_contents($root . '/docs/03-architecture/wave12-closure-report.md');
foreach (['status: closed', 'Wave 12 — закрито', '12.0–12.26', 'Panther E2E'] as $marker) {
    if (!str_contains($closure, $marker)) {
        throw new RuntimeException('Wave 12 closure report is incomplete: ' . $marker);
    }
}

echo "Wave 12 final acceptance contract passed. Web Experience Platform v1 is CLOSED.\n";
