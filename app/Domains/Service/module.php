<?php
declare(strict_types=1);

return [
    'id' => 'service',
    'name' => 'Service',
    'version' => '0.1.0',
    'schema_version' => '0.1.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Service operations boundary for cases, requests, tickets, SLA, assignment and resolution.',
    'icon' => 'headphones',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'migration_files' => [],
        'capabilities' => [],
    ],
];
