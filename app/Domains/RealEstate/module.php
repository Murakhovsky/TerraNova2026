<?php
declare(strict_types=1);

return [
    'id' => 'real_estate',
    'name' => 'Real Estate',
    'version' => '0.2.0',
    'schema_version' => '0.2.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Brokerage orchestration over canonical Property assets: Opportunity → Property Match → Offer → Viewing → Reservation.',
    'icon' => 'house',
    'dependencies' => ['property', 'sales'],
    'enabled_by_default' => true,
    'contributions' => [
        'runtime_module_service' => 'realEstateDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'cross_domain_contracts' => [
            [
                'contract' => 'Domains\\Property\\Contract\\PropertyBrokerageReferencePort',
                'role' => 'requires',
                'counterpart' => 'property',
                'kind' => 'synchronous_port',
                'purpose' => 'Resolve canonical Property and Inventory snapshots for brokerage without reading Property storage directly.',
            ],
            [
                'contract' => 'Domains\\Property\\Application\\Contract\\PropertyInventoryCommandInterface',
                'role' => 'requires',
                'counterpart' => 'property',
                'kind' => 'command_port',
                'purpose' => 'Request canonical inventory status changes and reservations from Property ownership.',
            ],
            [
                'contract' => 'Domains\\RealEstate\\Application\\Contract\\SalesOpportunityReferenceInterface',
                'role' => 'requires',
                'counterpart' => 'sales',
                'kind' => 'anti_corruption_port',
                'purpose' => 'Validate Sales opportunity references without reading Sales storage directly.',
            ],
        ],
        'migration_files' => [
            'app/migrations/20260918_000062_real_estate_wave9_cutover.sql',
        ],
        'capabilities' => [
            'real_estate.brokerage',
            'real_estate.property_match',
            'real_estate.offer',
            'real_estate.viewing',
            'real_estate.reservation',
            'real_estate.api.v1',
        ],
    ],
];
