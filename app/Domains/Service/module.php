<?php
declare(strict_types=1);

return [
    'id' => 'service',
    'name' => 'Service',
    'version' => '0.2.0',
    'schema_version' => '0.2.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Executable service operations runtime for Request → Ticket → Assignment/SLA → Escalation → Resolution → Close.',
    'icon' => 'headphones',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'serviceDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'migration_files' => [
            'app/migrations/20260919_000064_service_wave11_cutover.sql',
        ],
        'capabilities' => [
            'service.request',
            'service.ticket',
            'service.assignment',
            'service.sla',
            'service.escalation',
            'service.resolution',
            'service.api.v1',
        ],
    ],
];
