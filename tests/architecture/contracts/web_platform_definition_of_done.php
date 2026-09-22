<?php

declare(strict_types=1);

/**
 * Wave 12 Definition of Done evidence manifest.
 *
 * Each category mirrors the canonical Wave 12 TZ. The listed files are the
 * executable or architecture evidence proving that category is implemented.
 *
 * @return array<string, list<string>>
 */
return [
    'Architecture' => [
        'tests/architecture/frontend_interface.php',
        'tests/architecture/web_experience_wave12_foundation.php',
        'tests/architecture/web_experience_wave12_extensions.php',
        'tests/architecture/web_platform_v1_freeze.php',
    ],
    'Foundation' => [
        'tests/architecture/web_experience_wave12_toolkit.php',
        'tests/architecture/web_experience_wave12_design_system.php',
        'tests/architecture/web_experience_visual_foundation.php',
    ],
    'UI Runtime' => [
        'tests/architecture/web_experience_wave12_runtime.php',
        'tests/architecture/web_experience_wave12_interactions.php',
    ],
    'Platform' => [
        'tests/architecture/web_experience_wave12_shell.php',
        'tests/architecture/web_experience_wave12_extensions.php',
        'tests/architecture/web_experience_wave12_actions.php',
        'tests/architecture/web_experience_wave12_workspace.php',
        'tests/architecture/web_experience_wave12_data.php',
        'tests/architecture/web_experience_wave12_forms.php',
        'tests/architecture/web_experience_wave12_search.php',
        'tests/architecture/web_experience_wave12_native.php',
    ],
    'Realtime' => [
        'tests/architecture/web_experience_wave12_realtime.php',
        'tests/architecture/web_experience_wave12_async_operations.php',
    ],
    'AI' => [
        'tests/architecture/web_experience_wave12_ai_ui.php',
        'tests/architecture/web_experience_wave12_runtime_actions.php',
    ],
    'Workflow' => [
        'tests/architecture/kernel_workflow_boundaries.php',
        'tests/unit/kernel_workflow_engine.php',
        'symfony/tests/workflow_state_manager_contract.php',
        'tests/architecture/web_experience_wave12_runtime_actions.php',
        'tests/architecture/web_experience_wave12_audit_history.php',
    ],
    'Mobile' => [
        'tests/architecture/web_experience_wave12_mobile.php',
        'tests/architecture/web_experience_wave12_data.php',
        'tests/architecture/web_experience_wave12_forms.php',
    ],
    'PWA' => [
        'tests/architecture/web_experience_wave12_pwa.php',
        'symfony/src/Command/PwaFoundationSmokeCommand.php',
    ],
    'Native-ready' => [
        'tests/architecture/web_experience_wave12_native.php',
        'symfony/src/Command/NativeReadySmokeCommand.php',
    ],
    'Security' => [
        'tests/architecture/web_experience_wave12_security.php',
        'symfony/tests/sales_cutover_access_contract.php',
    ],
    'Quality' => [
        'tests/architecture/web_experience_wave12_testing.php',
        'tests/component/web_experience_components.php',
        'symfony/tests/Panther/WebExperiencePantherTest.php',
        'tests/browser/web_platform_quality.mjs',
    ],
    'Operations' => [
        'tests/architecture/web_experience_wave12_performance_observability.php',
        'symfony/tests/http_correlation_contract.php',
        'symfony/tests/web_telemetry_contract.php',
        'tests/browser/web_performance_budget.mjs',
    ],
    'Reference Vertical' => [
        'tests/architecture/web_experience_wave12_sales_reference.php',
        'tests/architecture/web_experience_wave12_sales_cutover.php',
        'symfony/tests/sales_cutover_access_contract.php',
        'tests/smoke/sales_workspace.php',
        'tests/browser/sales_workspace.mjs',
    ],
];
