<?php
declare(strict_types=1);

return [
    'id' => 'property',
    'name' => 'Property',
    'version' => '0.2.3',
    'schema_version' => '0.2.2',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Canonical registry of physical real-estate assets, their intrinsic facts, location, lifecycle and relations.',
    'icon' => 'building',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'propertyDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [
            'propertyRouteContributor',
        ],
        'configuration_provisioner_services' => [
            'propertyModuleConfigurationProvisioner',
        ],
        'extension_services' => [
            'web.navigation' => [
                'propertyNavigationContributor',
            ],
        ],
        'migration_files' => [
            'app/migrations/20260914_000048_web_v041_property_tenancy.sql',
            'app/migrations/20260914_000050_property_v022_tenant_boundary.sql',
        ],
        'capabilities' => [
            'property.registry',
            'property.read',
            'property.write',
            'property.intake',
            'property.media',
            'property.catalog',
        ],
    ],
];
