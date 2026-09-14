<?php
declare(strict_types=1);

return [
    'id' => 'property',
    'name' => 'Property',
    'version' => '0.2.0',
    'schema_version' => '0.1.1',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Canonical registry of physical real-estate assets, their intrinsic facts, location, lifecycle and relations.',
    'icon' => 'building',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [
            'web.navigation' => [
                'propertyNavigationContributor',
            ],
        ],
        'migration_files' => [
            'app/migrations/20260914_000048_web_v041_property_tenancy.sql',
        ],
        'capabilities' => [],
    ],
];
