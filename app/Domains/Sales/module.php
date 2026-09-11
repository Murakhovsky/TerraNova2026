<?php
declare(strict_types=1);

return [
    'id' => 'sales',
    'name' => 'Sales',
    'version' => '0.7.1',
    'schema_version' => '0.7.1',
    'kernel_constraint' => '^0.8.0',
    'description' => 'Sales operations, CRM workflow, intelligence and automation.',
    'icon' => 'chart-line',
    'dependencies' => [],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'salesDomainModule',
        'job_handler_services' => [
            'salesCrmInboxJobHandler',
        ],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'migration_files' => [
            'app/migrations/20260910_000030_sales_v071_configuration_ownership.sql',
        ],
        'capabilities' => [
            'sales.workspace.use',
            'sales.director.view',
            'sales.admin.view',
            'sales.admin.pipeline.manage',
            'sales.admin.rules.manage',
            'sales.admin.agents.manage',
            'sales.admin.policies.manage',
            'sales.admin.teams.manage',
            'sales.admin.integrations.manage',
            'sales.admin.audit.view',
        ],
    ],
];
