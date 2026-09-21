<?php
declare(strict_types=1);

return [
    'id' => 'growth',
    'name' => 'Growth',
    'version' => '0.2.0',
    'schema_version' => '0.2.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Opportunity intelligence from observable signals to qualified business opportunity handoff.',
    'icon' => 'radar',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => 'growthDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'migration_files' => ['app/migrations/20260921_000067_growth_v020_runtime.sql'],
        'capabilities' => [
            'growth.signal.detect',
            'growth.candidate.research',
            'growth.candidate.score',
            'growth.candidate.qualify',
            'growth.handoff.prepare',
            'growth.candidate.monitor',
        ],
    ],
];
