<?php
declare(strict_types=1);

return [
    'id'=>'capital_markets',
    'name'=>'Capital Markets',
    'version'=>'0.2.0',
    'schema_version'=>'0.2.0',
    'kernel_constraint'=>'>=0.11.0 <0.12.0',
    'description'=>'Autonomous Capital Markets bounded context for instrument identity, economic relationships and venue registry. Foundation has no market-data or trading execution runtime.',
    'icon'=>'chart-candlestick',
    'dependencies'=>[],
    'enabled_by_default'=>false,
    'contributions'=>[
        'runtime_module_service'=>'capitalMarketsDomainModule',
        'job_handler_services'=>[],
        'api_route_contributor_services'=>[],
        'configuration_provisioner_services'=>[],
        'extension_services'=>[
            'web.navigation'=>['capitalMarketsNavigationContributor'],
            'web.search'=>['capitalMarketsNavigationContributor'],
            'web.commands'=>['capitalMarketsNavigationContributor'],
            'web.workspace'=>['capitalMarketsNavigationContributor'],
        ],
        'cross_domain_contracts'=>[],
        'migration_files'=>[
            'app/migrations/20261005_000124_capital_markets_foundation.sql',
        ],
        'capabilities'=>[
            'capital_markets.view',
            'capital_markets.manage',
            'capital_markets.instrument.view',
            'capital_markets.instrument.manage',
            'capital_markets.relationship.view',
            'capital_markets.relationship.manage',
            'capital_markets.venue.view',
            'capital_markets.venue.manage',
            'capital_markets.audit.view',
        ],
    ],
];
