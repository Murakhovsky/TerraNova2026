<?php
declare(strict_types=1);

return [
    'id' => 'sales',
    'name' => 'Sales',
    'version' => '0.8.6',
    'schema_version' => '0.8.6',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
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
        'configuration_provisioner_services' => [
            'salesModuleConfigurationProvisioner',
        ],
        'extension_services' => [
            'event.consumers' => [
                'salesHistoricalEventConsumer',
            ],
            'web.navigation' => ['salesNavigationContributor'],
            'web.commands' => ['salesNavigationContributor'],
            'web.workspace' => ['salesNavigationContributor'],
        ],
        'cross_domain_contracts' => [
            [
                'contract' => 'Domains\\Diagnostic\\Methodology\\Model\\MethodologyPack',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'methodology_contract',
                'purpose' => 'Reuse the canonical deterministic Diagnostic methodology model for Sales-specific diagnostic definitions.',
            ],
            [
                'contract' => 'Domains\\Diagnostic\\Methodology\\CompiledDiagnosticPack',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'methodology_contract',
                'purpose' => 'Execute Sales benchmark datasets against a compiled canonical Diagnostic methodology.',
            ],
            [
                'contract' => 'Domains\\Diagnostic\\Evaluation\\EvaluationDatasetRunner',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'evaluation_contract',
                'purpose' => 'Reuse canonical deterministic benchmark evaluation without duplicating scoring logic in Sales.',
            ],
            [
                'contract' => 'Domains\\Diagnostic\\Methodology\\Engine\\MethodologyEngine',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'evaluation_contract',
                'purpose' => 'Delegate Sales diagnostic scoring and validation to the canonical deterministic engine.',
            ],
            [
                'contract' => 'Domains\\Diagnostic\\Methodology\\Input\\DiagnosticInput',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'methodology_contract',
                'purpose' => 'Pass canonical evidence-backed diagnostic input into deterministic Sales evaluation.',
            ],
            [
                'contract' => 'Domains\\Diagnostic\\Methodology\\Result\\DiagnosticResult',
                'role' => 'requires',
                'counterpart' => 'diagnostic',
                'kind' => 'methodology_contract',
                'purpose' => 'Expose canonical deterministic diagnostic result semantics through the Sales wrapper.',
            ],
            [
                'contract' => 'Domains\\Property\\Contract\\PropertyReferencePort',
                'role' => 'requires',
                'counterpart' => 'property',
                'kind' => 'synchronous_port',
                'purpose' => 'Resolve canonical Property references inside Sales workflows without owning Property state.',
            ],
        ],
        'migration_files' => [
            'app/migrations/20260910_000030_sales_v071_configuration_ownership.sql',
            'app/migrations/20260913_000044_sales_v081_historical_stage_history.sql',
            'app/migrations/20260913_000045_sales_v082_funnel_metrics.sql',
            'app/migrations/20260913_000046_sales_v083_operational_performance.sql',
            'app/migrations/20260913_000047_sales_v086_hardening.sql',
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
