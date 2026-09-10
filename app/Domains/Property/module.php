<?php
declare(strict_types=1);

return [
    'id' => 'property',
    'name' => 'Property',
    'version' => '0.1.0',
    'schema_version' => '0.1.0',
    'kernel_constraint' => '^0.7.1',
    'description' => 'Property catalog, presentation and real-estate workflows.',
    'icon' => 'building',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'migration_files' => [],
        'capabilities' => [],
    ],
];
