<?php
declare(strict_types=1);

return [
    'id' => 'property',
    'name' => 'Property',
    'version' => '0.8.0',
    'schema_version' => '0.7.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Canonical real-estate asset domain with Inventory, Listing/Publication, history, cross-domain references and supply/demand analytics.',
    'icon' => 'building',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'propertyDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => ['propertyRouteContributor'],
        'configuration_provisioner_services' => ['propertyModuleConfigurationProvisioner'],
        'extension_services' => ['web.navigation' => ['propertyNavigationContributor']],
        'migration_files' => [
            'app/migrations/20260914_000048_web_v041_property_tenancy.sql',
            'app/migrations/20260914_000050_property_v022_tenant_boundary.sql',
            'app/migrations/20260914_000051_property_v030_asset_registry.sql',
            'app/migrations/20260914_000052_property_v040_identity_provenance.sql',
            'app/migrations/20260914_000053_property_v050_inventory.sql',
            'app/migrations/20260914_000054_property_v060_listings_publication.sql',
            'app/migrations/20260914_000055_property_v070_history_contracts.sql',
        ],
        'capabilities' => [
            'property.registry','property.read','property.write','property.intake','property.media','property.catalog',
            'property.inventory','property.listing','property.publish','property.history','property.reference','property.analytics',
        ],
    ],
];
