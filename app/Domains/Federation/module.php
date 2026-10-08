<?php
declare(strict_types=1);

return [
    'id' => 'federation',
    'name' => 'COS Federation',
    'version' => '1.0.0',
    'schema_version' => '1.0.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Opt-in canonical Goal Plan approval Action bridge; no duplicate workflow engine.',
    'icon' => 'network',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => 'federationDomainModule',
        'capabilities' => ['federation.plan.approval'],
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'migration_files' => [],
    ],
];
