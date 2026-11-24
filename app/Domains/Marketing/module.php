<?php
declare(strict_types=1);

/**
 * D1 foundation. Live bindings require separately wired adapters; a declaration
 * never grants an AI, scheduled job or worker execution authority.
 */
$manifest = [
    'id' => 'marketing',
    'name' => 'Marketing',
    'version' => '0.2.2',
    'schema_version' => '0.2.2',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Marketing M2 creative drafts/analyst review and Meta read-only evidence; Finance owns budgets and paid mutations remain blocked.',
    'icon' => 'megaphone',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => null,
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [
            'web.navigation' => ['marketingNavigationContributor'],
            'web.search' => ['marketingNavigationContributor'],
            'web.commands' => ['marketingNavigationContributor'],
            'web.workspace' => ['marketingNavigationContributor'],
        ],
        'cross_domain_contracts' => [], // Finance F1 remains a future port, not a falsely wired dependency.
        'migration_files' => ['app/migrations/20261119_000152_marketing_foundation.sql','app/migrations/20261119_000154_marketing_planning_m2.sql'],
        'capabilities' => [
            'marketing.campaign.search',
            'marketing.campaign.get',
            'marketing.campaign.create',
            'marketing.paid.account.list',
            'marketing.paid.sync.history',
            'marketing.paid.spend.evidence.get',
            'marketing.paid.insights.query',
            'marketing.creative.create',
            'marketing.creative.revise',
            'marketing.creative.list',
            'marketing.analyst.proposal.submit',
            'marketing.analyst.proposal.list',
            'marketing.analyst.proposal.review',
            'marketing.campaign.update',
            'marketing.campaign.archive',
            'marketing.paid.sync.request',
            'marketing.paid.change.request',
            'marketing.paid.meta.campaign.create_paused',
            'marketing.paid.meta.campaign.activate',
            'marketing.paid.meta.campaign.pause',
        ],
        'capability_contracts' => [],
    ],
];

$contracts = [];
foreach ([
    'marketing.campaign.search' => ['query', 'none', 'marketing/campaign-search', 'cos.tenant.manage'],
    'marketing.campaign.get' => ['query', 'none', 'marketing/campaign-get', 'cos.tenant.manage'],
    'marketing.campaign.create' => ['command', 'internal', 'marketing/campaign-create', 'cos.tenant.manage'],
    'marketing.paid.account.list' => ['query', 'none', 'marketing/account-list', 'cos.tenant.manage'],
    'marketing.paid.sync.history' => ['query', 'none', 'marketing/sync-history', 'cos.tenant.manage'],
    'marketing.paid.spend.evidence.get' => ['query', 'none', 'marketing/spend-evidence-get', 'cos.tenant.manage'],
    'marketing.paid.insights.query' => ['query', 'none', 'marketing/insights-query', 'cos.tenant.manage'],
] as $id => [$kind, $sideEffect, $schema, $permission]) {
    $contracts[] = [
        'id' => $id,
        'version' => '1.0.0',
        'owner_domain' => 'marketing',
        'kind' => $kind,
        'input_schema' => 'resources/contracts/'.$schema.'-input.schema.json',
        'output_schema' => 'resources/contracts/'.$schema.'-output.schema.json',
        'execution_binding' => $kind.':'.$id,
        'side_effect_level' => $sideEffect,
        'permission' => $permission,
        'approval_policy' => 'none',
        'idempotency' => $kind === 'command' ? 'required' : 'none',
        'timeout_policy' => $kind === 'command' ? ['seconds' => 30] : [],
        'retry_policy' => $kind === 'command' ? ['max_attempts' => 1] : [],
        'failure_contract' => $kind === 'command' ? 'An ambiguous database outcome is inspected by stable idempotency key before any new draft is attempted.' : '',
        'lifecycle' => 'experimental',
        'tests' => ['tests/unit/marketing_domain_foundation.php'],
        'documentation' => 'docs/04-domains/marketing/overview.md',
    ];
}
foreach ([
    'marketing.creative.create' => ['command','internal','marketing/creative-create'],
    'marketing.creative.revise' => ['command','internal','marketing/creative-revise'],
    'marketing.creative.list' => ['query','none','marketing/creative-list'],
    'marketing.analyst.proposal.submit' => ['command','internal','marketing/analyst-proposal-submit'],
    'marketing.analyst.proposal.list' => ['query','none','marketing/analyst-proposal-list'],
    'marketing.analyst.proposal.review' => ['command','internal','marketing/analyst-proposal-review'],
] as $id => [$kind,$sideEffect,$schema]) {
    $contracts[] = [
        'id' => $id,
        'version' => '1.0.0',
        'owner_domain' => 'marketing',
        'kind' => $kind,
        'input_schema' => 'resources/contracts/'.$schema.'-input.schema.json',
        'output_schema' => 'resources/contracts/'.$schema.'-output.schema.json',
        'execution_binding' => $kind.':'.$id,
        'side_effect_level' => $sideEffect,
        'permission' => 'cos.tenant.manage',
        'approval_policy' => $kind === 'command' ? 'conditional' : 'none',
        'idempotency' => $kind === 'command' ? 'required' : 'none',
        'timeout_policy' => $kind === 'command' ? ['seconds'=>30] : [],
        'retry_policy' => $kind === 'command' ? ['max_attempts'=>1] : [],
        'failure_contract' => 'Creative and analyst planning are internal records only; no Meta, Hermes or Finance mutation. Reconcile receipts before any retry.',
        'lifecycle' => 'experimental',
        'tests' => ['tests/unit/marketing_m2_planning.php'],
        'documentation' => 'docs/04-domains/marketing/overview.md',
    ];
}

$manifest['contributions']['capability_contracts'] = $contracts;
return $manifest;
