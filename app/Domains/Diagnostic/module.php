<?php
declare(strict_types=1);

return [
    'id' => 'diagnostic',
    'name' => 'Diagnostics',
    'version' => '0.5.4',
    'schema_version' => '0.5.4',
    'kernel_constraint' => '>=0.10.0 <0.11.0',
    'description' => 'Business diagnostics, methodology, interviews and reporting.',
    'icon' => 'scan-search',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [
            'web.navigation' => [
                'diagnosticNavigationContributor',
            ],
        ],
        'migration_files' => [],
        'capabilities' => [],
    ],
];
