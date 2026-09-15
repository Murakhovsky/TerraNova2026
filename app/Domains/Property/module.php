<?php
declare(strict_types=1);

return [
    'id' => 'property',
    'name' => 'Property',
    'version' => '0.12.0',
    'schema_version' => '0.12.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Canonical real-estate asset runtime with tenant-safe Asset, Inventory, Listing/Publication writes, domain events, history, intelligence, network interoperability and one-way legacy compatibility projection.',
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
            'app/migrations/20260914_000056_property_v090_intelligence.sql',
            'app/migrations/20260914_000057_property_v0100_external_network.sql',
            'app/migrations/20260914_000058_property_v0110_hardening.sql',
            'app/migrations/20260915_000059_property_v0120_runtime_cutover.sql',
        ],
        'capabilities' => [
            'property.registry','property.read','property.write','property.intake','property.media','property.catalog',
            'property.inventory','property.listing','property.publish','property.history','property.reference','property.analytics',
            'property.intelligence','property.network','property.identity.review','property.runtime.canonical',
        ],
    ],
];
