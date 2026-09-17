<?php
declare(strict_types=1);

return [
    'id' => 'procurement',
    'name' => 'Procurement',
    'version' => '0.1.0',
    'schema_version' => '0.1.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Procurement boundary for suppliers, purchase requests, quotes, orders and deliveries.',
    'icon' => 'shopping-cart',
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
