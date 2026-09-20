<?php
declare(strict_types=1);

return [
    'id' => 'diagnostic',
    'name' => 'Diagnostics',
    'version' => '0.6.1',
    'schema_version' => '0.6.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Business diagnostics, methodology, interviews, reporting and closed-loop recommendations.',
    'icon' => 'scan-search',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'diagnosticDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [
            'event.consumers' => [
                'diagnosticActionOutcomeHandler',
            ],
        ],
        'migration_files' => [
            'app/migrations/20260914_000049_diagnostic_runtime_v060.sql',
        ],
        'capabilities' => [],
    ],
];
