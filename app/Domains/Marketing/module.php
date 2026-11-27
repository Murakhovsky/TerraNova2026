<?php
declare(strict_types=1);

/**
 * D1 foundation. Live bindings require separately wired adapters; a declaration
 * never grants an AI, scheduled job or worker execution authority.
 */
$manifest = [
    'id' => 'marketing',
    'name' => 'Marketing',
    'version' => '0.2.3',
    'schema_version' => '0.2.3',
    'kernel_constraint' => '>=0.11.0 <0.12.0',
    'description' => 'Marketing M2 creative drafts/analyst review and Meta read-only evidence; Finance owns budgets and paid mutations remain blocked.',
    'icon' => 'megaphone',
    'dependencies' => [],
    'enabled_by_default' => false,
    'contributions' => [
        'runtime_module_service' => 'marketingDomainModule',
        'job_handler_services' => [],
        'api_route_contributor_services' => [],
        'configuration_provisioner_services' => [],
        'extension_services' => [
            'web.navigation' => ['marketingNavigationContributor'],
            'web.search' => ['marketingNavigationContributor'],
            'web.commands' => ['marketingNavigationContributor'],
            'web.workspace' => ['marketingNavigationContributor'],
            'web.entity_links' => ['marketingNavigationContributor'],
        ],
        'cross_domain_contracts' => [
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingOptimizationReviewEvidenceReadPort',
                'role'=>'requires','counterpart'=>'federation','kind'=>'synchronous_port',
                'purpose'=>'Read exact persisted trusted review Outcome for learning; no new approval or execution authority.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingAttributionCandidateReadPort',
                'role'=>'requires','counterpart'=>'sales','kind'=>'synchronous_port',
                'purpose'=>'Bounded tenant-scoped native lead and order ID discovery; no customer data or Sales mutation.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingCommerceFinancialReadPort',
                'role' => 'requires', 'counterpart' => 'finance', 'kind' => 'synchronous_port',
                'purpose' => 'Read independently confirmed order collection evidence, without payment authority or revenue inference.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingCommerceOrderReadPort',
                'role' => 'requires', 'counterpart' => 'sales', 'kind' => 'synchronous_port',
                'purpose' => 'Read native Order placement, independently observed UTM journey and optional bounded lifecycle revisions; no Sales write, payment or revenue assertion.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingSalesAttributionReadPort',
                'role' => 'requires', 'counterpart' => 'sales', 'kind' => 'synchronous_port',
                'purpose' => 'Read minimal native lead UTM and linked won deal facts; no Sales mutation or contact data exposure.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingFinanceAnalyticsReadPort',
                'role' => 'requires', 'counterpart' => 'finance', 'kind' => 'synchronous_port',
                'purpose' => 'Read native settled budget receipt bound to exact paid plan, without financial mutation.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingPaidExposureClaimPort',
                'role' => 'requires', 'counterpart' => 'finance', 'kind' => 'synchronous_port',
                'purpose' => 'Canonical activation worker requests one-shot Finance-owned exposure claim; no release or spend settlement.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingFinanceFundingReadPort',
                'role' => 'requires', 'counterpart' => 'finance', 'kind' => 'synchronous_port',
                'purpose' => 'Observe Finance-owned envelope and reservation against immutable Marketing funding candidate; no financial mutation or execution authority.',
            ],
            [
                'contract' => 'Domains\\Marketing\\Application\\Contract\\MarketingProductDraftReadPort',
                'role' => 'requires',
                'counterpart' => 'product_management',
                'kind' => 'synchronous_port',
                'purpose' => 'Search current tenant-scoped Product variants and read base price facts for Marketing draft planning, without Product SQL or launch authorization.',
            ],
        ],
        'migration_files' => ['app/migrations/20261119_000152_marketing_foundation.sql','app/migrations/20261119_000154_marketing_planning_m2.sql','app/migrations/20261124_000162_marketing_audience_adsets.sql','app/migrations/20261124_000163_marketing_analyst_execution.sql','app/migrations/20261124_000164_marketing_analyst_decisions.sql','app/migrations/20261124_000165_marketing_paid_launch_plans.sql','app/migrations/20261124_000167_marketing_paid_operation_journal.sql','app/migrations/20261124_000192_marketing_m4_attribution_intents.sql','app/migrations/20261124_000193_marketing_media_analytics.sql','app/migrations/20261126_009020_marketing_attribution_schedules.sql','app/migrations/20261126_009021_marketing_experiment_protocols.sql','app/migrations/20261126_009022_marketing_optimization_receipts.sql','app/migrations/20261126_009023_marketing_optimization_schedules.sql'],
        'capabilities' => [
            'marketing.campaign.search',
            'marketing.campaign.get',
            'marketing.campaign.create',
            'marketing.paid.account.list',
            'marketing.paid.sync.history',
            'marketing.paid.spend.evidence.get',
            'marketing.paid.insights.query',
            'marketing.product.draft_check.get',
            'marketing.product.selector.search',
            'marketing.creative.create',
            'marketing.creative.revise',
            'marketing.creative.list',
            'marketing.analyst.context.get',
            'marketing.analyst.run.list',
            'marketing.analyst.run.review',
            'marketing.analyst.run.recover',
            'marketing.analyst.runtime.status',
            'marketing.analyst.run.request',
            'marketing.analyst.proposal.submit',
            'marketing.analyst.proposal.list',
            'marketing.analyst.proposal.review',
            'marketing.audience.save',
            'marketing.audience.list',
            'marketing.adset.save',
            'marketing.adset.list',
            'marketing.campaign.update',
            'marketing.campaign.archive',
            'marketing.paid.sync.request',
            'marketing.paid.change.request',
            'marketing.paid.operation.status',
            'marketing.paid.spend.compare',
            'marketing.paid.launch_plan.create',
            'marketing.paid.launch_plan.get',
            'marketing.paid.funding.check',
            'marketing.paid.meta.campaign.create_paused',
            'marketing.paid.meta.campaign.activate',
            'marketing.paid.meta.campaign.pause',
            'marketing.attribution.capture',
            'marketing.attribution.discover',
            'marketing.attribution.tracking.get',
            'marketing.analytics.report.get',
            'marketing.analytics.media.report',
            'marketing.analytics.media.sync',
            'marketing.attribution.commerce.inspect',
            'marketing.attribution.commerce.capture',
            'marketing.analytics.commerce.report',
            'marketing.attribution.sweep',
            'marketing.attribution.journey.get',
            'marketing.analytics.economics.report',
            'marketing.analytics.monitor.get',
            'marketing.optimization.budget.preview',
            'marketing.optimization.audience.preview',
            'marketing.experiment.register',
            'marketing.experiment.get',
            'marketing.experiment.study.inspect',
            'marketing.optimization.creative.preview',
            'marketing.optimization.rule.evaluate',
            'marketing.optimization.rule.plan.propose',
            'marketing.optimization.rule.review.record',
            'marketing.optimization.learning.preview',
            'marketing.optimization.learning.get',
            'marketing.optimization.learning.plan.propose',
            'marketing.optimization.learning.record',
            'marketing.optimization.replacement.plan.propose',
            'marketing.optimization.replacement.record',
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
    'marketing.analytics.economics.report' => ['query', 'none', 'marketing/analytics-economics', 'cos.tenant.manage'],
    'marketing.analytics.monitor.get' => ['query', 'none', 'marketing/analytics-monitor', 'cos.tenant.manage'],
    'marketing.optimization.audience.preview' => ['query', 'none', 'marketing/optimization-audience-preview', 'cos.tenant.manage'],
    'marketing.optimization.rule.evaluate' => ['query', 'none', 'marketing/optimization-rule-evaluate', 'cos.tenant.manage'],
    'marketing.optimization.budget.preview' => ['query', 'none', 'marketing/optimization-budget-preview', 'cos.tenant.manage'],
    'marketing.attribution.journey.get' => ['query', 'none', 'marketing/attribution-journey', 'cos.tenant.manage'],
    'marketing.attribution.discover' => ['query', 'none', 'marketing/attribution-discover', 'cos.tenant.manage'],
    'marketing.attribution.capture' => ['command', 'internal', 'marketing/attribution-capture', 'cos.tenant.manage'],
    'marketing.attribution.tracking.get' => ['query', 'none', 'marketing/attribution-tracking', 'cos.tenant.manage'],
    'marketing.analytics.media.report' => ['query', 'none', 'marketing/media-analytics-report', 'cos.tenant.manage'],
    'marketing.analytics.media.sync' => ['command', 'internal', 'marketing/media-analytics-sync', 'cos.tenant.manage'],
    'marketing.attribution.commerce.inspect' => ['query', 'none', 'marketing/commerce-inspect', 'cos.tenant.manage'],
    'marketing.attribution.commerce.capture' => ['command', 'internal', 'marketing/commerce-capture', 'cos.tenant.manage'],
    'marketing.analytics.commerce.report' => ['query', 'none', 'marketing/commerce-report', 'cos.tenant.manage'],
    'marketing.analytics.report.get' => ['query', 'none', 'marketing/analytics-report', 'cos.tenant.manage'],
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
        'tests' => $id==='marketing.optimization.audience.preview'?['tests/unit/marketing_domain_foundation.php','tests/integration/marketing_paid_approved_goal_e2e_mysql.php']:(str_contains($id,'attribution')||str_contains($id,'analytics')||str_contains($id,'optimization')?['tests/unit/marketing_domain_foundation.php','tests/integration/marketing_m4_attribution_mysql.php']:['tests/unit/marketing_domain_foundation.php']),
        'documentation' => 'docs/04-domains/marketing/overview.md',
    ];
}

$contracts[] = [
    'id' => 'marketing.product.draft_check.get',
    'version' => '1.0.0',
    'owner_domain' => 'marketing',
    'kind' => 'query',
    'input_schema' => 'resources/contracts/marketing/product-draft-check-input.schema.json',
    'output_schema' => 'resources/contracts/marketing/product-draft-check-output.schema.json',
    'execution_binding' => 'query:marketing.product.draft_check.get',
    'side_effect_level' => 'none',
    'permission' => 'cos.tenant.manage',
    'approval_policy' => 'none',
    'idempotency' => 'none',
    'lifecycle' => 'experimental',
    'failure_contract' => 'Product source is re-read per request; Product module disabled or missing tenant evidence fails closed, and no advertising launch authorization is implied.',
    'tests' => ['tests/unit/marketing_product_draft_check.php'],
    'documentation' => 'docs/04-domains/marketing/overview.md',
];

$contracts[] = [
    'id' => 'marketing.product.selector.search',
    'version' => '1.0.0',
    'owner_domain' => 'marketing',
    'kind' => 'query',
    'input_schema' => 'resources/contracts/marketing/product-selector-search-input.schema.json',
    'output_schema' => 'resources/contracts/marketing/product-selector-search-output.schema.json',
    'execution_binding' => 'query:marketing.product.selector.search',
    'side_effect_level' => 'none',
    'permission' => 'cos.tenant.manage',
    'approval_policy' => 'none',
    'idempotency' => 'none',
    'lifecycle' => 'experimental',
    'failure_contract' => 'Disabled Product module or malformed source fails closed; filtered variant pages are not advertising approval.',
    'tests' => ['tests/unit/marketing_product_draft_check.php'],
    'documentation' => 'docs/04-domains/marketing/overview.md',
];

foreach ([
    'marketing.audience.save' => ['command','internal','marketing/audience-save'],
    'marketing.audience.list' => ['query','none','marketing/audience-list'],
    'marketing.adset.save' => ['command','internal','marketing/adset-save'],
    'marketing.adset.list' => ['query','none','marketing/adset-list'],
    'marketing.creative.create' => ['command','internal','marketing/creative-create'],
    'marketing.creative.revise' => ['command','internal','marketing/creative-revise'],
    'marketing.creative.list' => ['query','none','marketing/creative-list'],
    'marketing.analyst.context.get' => ['query','none','marketing/analyst-context-get'],
    'marketing.analyst.run.list' => ['query','none','marketing/analyst-run-list'],
    'marketing.analyst.run.review' => ['command','internal','marketing/analyst-run-review'],
    'marketing.analyst.run.recover' => ['command','internal','marketing/analyst-run-recover'],
    'marketing.analyst.runtime.status' => ['query','none','marketing/analyst-runtime-status'],
    'marketing.analyst.proposal.submit' => ['command','internal','marketing/analyst-proposal-submit'],
    'marketing.analyst.proposal.list' => ['query','none','marketing/analyst-proposal-list'],
    'marketing.analyst.proposal.review' => ['command','internal','marketing/analyst-proposal-review'],
    'marketing.paid.operation.status' => ['query','none','marketing/paid-operation-status'],
    'marketing.paid.spend.compare' => ['query','none','marketing/paid-spend-compare'],
    'marketing.paid.launch_plan.create' => ['command','internal','marketing/paid-launch-plan-create'],
    'marketing.paid.launch_plan.get' => ['query','none','marketing/paid-launch-plan-get'],
    'marketing.paid.funding.check' => ['query','none','marketing/paid-funding-check'],
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

// Immutable internal experiment plan; observation is advisory and requires native terminal sources.
foreach([
    'marketing.experiment.register'=>['command','internal','marketing/experiment-register'],
    'marketing.experiment.get'=>['query','none','marketing/experiment-get'],
    'marketing.experiment.study.inspect'=>['query','none','marketing/experiment-study-inspect'],
    'marketing.optimization.creative.preview'=>['query','none','marketing/optimization-creative-preview'],
] as $id=>[$kind,$sideEffect,$schema]){
    $contracts[]=[
        'id'=>$id,'version'=>'1.0.0','owner_domain'=>'marketing','kind'=>$kind,
        'input_schema'=>'resources/contracts/'.$schema.'-input.schema.json','output_schema'=>'resources/contracts/'.$schema.'-output.schema.json',
        'execution_binding'=>$kind.':'.$id,'side_effect_level'=>$sideEffect,'permission'=>'cos.tenant.manage',
        'approval_policy'=>$kind==='command'?'conditional':'none','idempotency'=>$kind==='command'?'required':'none',
        'timeout_policy'=>$kind==='command'?['seconds'=>30]:[],'retry_policy'=>$kind==='command'?['max_attempts'=>1]:[],
        'failure_contract'=>'Immutable protocol only; read back exact registration and source pins. No randomized evidence, provider writes, budget or activation authority.',
        'lifecycle'=>'experimental','tests'=>['tests/unit/marketing_domain_foundation.php','tests/integration/marketing_paid_approved_goal_e2e_mysql.php'],
        'documentation'=>'docs/04-domains/marketing/overview.md',
    ];
}

// External advice is executable only through an approved canonical Workflow Action.
$contracts[] = [
    'id'=>'marketing.analyst.run.request','version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
    'input_schema'=>'resources/contracts/marketing/analyst-run-request-input.schema.json',
    'output_schema'=>'resources/contracts/marketing/analyst-run-request-output.schema.json',
    'execution_binding'=>'action:marketing.analyst.run.request','async'=>true,'side_effect_level'=>'external',
    'permission'=>'cos.tenant.manage','approval_policy'=>'required','idempotency'=>'required',
    'timeout_policy'=>['seconds'=>120],'retry_policy'=>['max_attempts'=>1],
    'failure_contract'=>'Reconcile uncertain external turn and conservative budget usage. Never retry blindly or approve paid activation.',
    'lifecycle'=>'experimental','tests'=>['tests/unit/marketing_m2_planning.php'],
    'documentation'=>'docs/04-domains/marketing/overview.md',
];

foreach(['create_paused','activate','pause'] as $operation){
    $id='marketing.paid.meta.campaign.'.$operation;
    $schema='marketing/meta-campaign-'.str_replace('_','-',$operation);
    $contracts[]=[
        'id'=>$id,'version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
        'input_schema'=>'resources/contracts/'.$schema.'-input.schema.json','output_schema'=>'resources/contracts/'.$schema.'-output.schema.json',
        'execution_binding'=>'action:'.$id,'async'=>true,'side_effect_level'=>'external','permission'=>'cos.tenant.manage',
        'approval_policy'=>'required','idempotency'=>'required','timeout_policy'=>['seconds'=>600],'retry_policy'=>['max_attempts'=>1],
        'failure_contract'=>'Provider operation may be uncertain; hold exposure, inspect native journal and reconcile independently; no blind retry.',
        'lifecycle'=>'experimental','tests'=>['tests/unit/marketing_m2_planning.php'],'documentation'=>'docs/04-domains/marketing/overview.md'
    ];
}
$contracts[]=[
    'id'=>'marketing.attribution.sweep','version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
    'input_schema'=>'resources/contracts/marketing/attribution-sweep-input.schema.json',
    'output_schema'=>'resources/contracts/marketing/attribution-sweep-output.schema.json',
    'execution_binding'=>'action:marketing.attribution.sweep','async'=>true,'side_effect_level'=>'internal',
    'permission'=>'cos.tenant.manage','approval_policy'=>'required','idempotency'=>'required',
    'timeout_policy'=>['seconds'=>120],'retry_policy'=>['max_attempts'=>1],
    'failure_contract'=>'Inspect immutable native receipt; no blind retry. Page exhaustion is not a complete conversion census.',
    'lifecycle'=>'experimental','tests'=>['tests/integration/marketing_m4_attribution_mysql.php'],
    'documentation'=>'docs/04-domains/marketing/overview.md',
];
// Optimization Goal proposal is internal only. Review record requires independent canonical approvals.
$contracts[]=[
    'id'=>'marketing.optimization.rule.plan.propose','version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
    'input_schema'=>'resources/contracts/marketing/optimization-review-plan-propose-input.schema.json',
    'output_schema'=>'resources/contracts/marketing/optimization-review-plan-propose-output.schema.json',
    'execution_binding'=>'command:marketing.optimization.rule.plan.propose','side_effect_level'=>'internal',
    'permission'=>'cos.tenant.manage','approval_policy'=>'conditional','idempotency'=>'required',
    'timeout_policy'=>['seconds'=>30],'retry_policy'=>['max_attempts'=>1],
    'failure_contract'=>'Exact rule hash required. Proposal creates no approval, Action or provider effect; reconcile immutable Plan key.',
    'lifecycle'=>'experimental','tests'=>['tests/integration/marketing_analyst_approved_goal_e2e_mysql.php'],
    'documentation'=>'docs/04-domains/marketing/overview.md',
];
$contracts[]=[
    'id'=>'marketing.optimization.rule.review.record','version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
    'input_schema'=>'resources/contracts/marketing/optimization-review-record-input.schema.json',
    'output_schema'=>'resources/contracts/marketing/optimization-review-record-output.schema.json',
    'execution_binding'=>'action:marketing.optimization.rule.review.record','async'=>true,'side_effect_level'=>'internal',
    'permission'=>'cos.tenant.manage','approval_policy'=>'required','idempotency'=>'required',
    'timeout_policy'=>['seconds'=>60],'retry_policy'=>['max_attempts'=>1],
    'failure_contract'=>'Revalidate exact native rule evidence after approvals. Reconcile immutable receipt before retry; review is not provider or Finance mutation.',
    'lifecycle'=>'experimental','tests'=>['tests/integration/marketing_analyst_approved_goal_e2e_mysql.php'],
    'documentation'=>'docs/04-domains/marketing/overview.md',
];
foreach([
    'marketing.optimization.learning.preview'=>['query','none','query','optimization-learning-preview','none'],
    'marketing.optimization.learning.get'=>['query','none','query','optimization-learning-get','none'],
    'marketing.optimization.learning.plan.propose'=>['command','internal','command','optimization-learning-plan-propose','conditional'],
    'marketing.optimization.learning.record'=>['command','internal','action','optimization-learning-record','required'],
] as $id=>[$kind,$effect,$binding,$schema,$approval]){
    $contracts[]=[
        'id'=>$id,'version'=>'1.0.0','owner_domain'=>'marketing','kind'=>$kind,
        'input_schema'=>'resources/contracts/marketing/'.$schema.'-input.schema.json',
        'output_schema'=>'resources/contracts/marketing/'.$schema.'-output.schema.json',
        'execution_binding'=>$binding.':'.$id,'side_effect_level'=>$effect,'permission'=>'cos.tenant.manage',
        'async'=>$binding==='action','approval_policy'=>$approval,'idempotency'=>$kind==='command'?'required':'none',
        'timeout_policy'=>$kind==='command'?['seconds'=>60]:[],'retry_policy'=>$kind==='command'?['max_attempts'=>1]:[],
        'failure_contract'=>'Require persisted trusted review Outcome and exact native follow-up batch pins. Receipt is observational only; never train, alter rules, mutate provider or Finance.',
        'lifecycle'=>'experimental','tests'=>['tests/integration/marketing_analyst_approved_goal_e2e_mysql.php'],
        'documentation'=>'docs/04-domains/marketing/overview.md',
    ];
}
foreach([
    'marketing.optimization.replacement.plan.propose'=>['command','optimization-replacement-plan-propose','conditional'],
    'marketing.optimization.replacement.record'=>['action','optimization-replacement-record','required'],
] as $id=>[$binding,$schema,$approval]){
    $contracts[]=[
        'id'=>$id,'version'=>'1.0.0','owner_domain'=>'marketing','kind'=>'command',
        'input_schema'=>'resources/contracts/marketing/'.$schema.'-input.schema.json',
        'output_schema'=>'resources/contracts/marketing/'.$schema.'-output.schema.json',
        'execution_binding'=>$binding.':'.$id,'side_effect_level'=>'internal','permission'=>'cos.tenant.manage',
        'async'=>$binding==='action','approval_policy'=>$approval,'idempotency'=>'required',
        'timeout_policy'=>['seconds'=>60],'retry_policy'=>['max_attempts'=>1],
        'failure_contract'=>'Atomic future replacement draft creation from exact historical native comparison. Never mutates an active provider object or Finance.',
        'lifecycle'=>'experimental','tests'=>['tests/integration/marketing_paid_approved_goal_e2e_mysql.php'],
        'documentation'=>'docs/04-domains/marketing/overview.md',
    ];
}
$manifest['contributions']['capability_contracts'] = $contracts;
return $manifest;
