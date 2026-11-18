<?php
declare(strict_types=1);

return [
    'id' => 'product_management',
    'name' => 'Product Management',
    'version' => '0.1.0',
    'schema_version' => '0.3.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Tenant-scoped goods catalog, stock ledger, base prices and channel observations.',
    'icon' => 'package',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => 'productManagementDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'cross_domain_contracts' => [],
        'migration_files' => [
            'app/migrations/20261118_000152_product_management_catalog.sql',
            'app/migrations/20261118_000153_product_management_inventory.sql',
            'app/migrations/20261118_000154_product_management_prices_import.sql',
        ],
        // Expose only implemented bindings, never aspirational commands.
        'capability_contracts' => [],
        'capabilities' => [],
    ],
];
