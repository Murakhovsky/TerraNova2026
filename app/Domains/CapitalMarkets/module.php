<?php
declare(strict_types=1);

return [
    'id' => 'capital_markets',
    'name' => 'Capital Markets',
    'version' => '0.1.0',
    'schema_version' => '0.1.0',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Autonomous capital-markets research and decision domain for instruments, venues, economic relationships, risk-aware strategies and execution evolution.',
    'icon' => 'chart-candlestick',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [],
        'cross_domain_contracts' => [],
        'migration_files' => [],
        'capabilities' => [
            'capital_markets.workspace.view',
            'capital_markets.instrument.read',
            'capital_markets.instrument.manage',
            'capital_markets.venue.read',
            'capital_markets.venue.manage',
            'capital_markets.research.read',
            'capital_markets.research.manage',
            'capital_markets.paper.execute',
            'capital_markets.risk.view',
            'capital_markets.risk.manage',
            'capital_markets.live.execute',
            'capital_markets.audit.view',
        ],
    ],
];
