<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);

$ruleCatalog = (string) file_get_contents($root . '/app/Domains/Sales/Automation/Rule/SalesRuleCatalog.php');
if (str_contains($ruleCatalog, 'PipelineStage')) {
    throw new RuntimeException('Sales V0.7.1: active Sales rules still depend on legacy PipelineStage.');
}
if (str_contains($ruleCatalog, "'deal.stage'")) {
    throw new RuntimeException('Sales V0.7.1: active Sales rules still depend on legacy deal.stage.');
}
if (!str_contains($ruleCatalog, "'deal.stage_code'")) {
    throw new RuntimeException('Sales V0.7.1: canonical stage_code is not used by Sales rules.');
}

$stageEvent = (string) file_get_contents($root . '/app/Domains/Sales/Automation/Event/DealStageChanged.php');
foreach (['pipeline_id', 'previous_stage_id', 'previous_stage_code', 'stage_id', 'stage_code'] as $field) {
    if (!str_contains($stageEvent, "'{$field}'")) {
        throw new RuntimeException('Sales V0.7.1: DealStageChanged is missing canonical field ' . $field);
    }
}

$ruleContext = (string) file_get_contents(
    $root . '/app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesRuleContextProvider.php'
);
if (!str_contains($ruleContext, 's.code AS stage_code')) {
    throw new RuntimeException('Sales V0.7.1: rule context does not expose canonical stage_code.');
}

$store = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlConfigurationStore.php'
);
foreach ([
    "ownership = \\'SYSTEM\\'",
    "ownership = \\'ADMIN\\'",
    'system_update_available',
    'cos_configuration_revisions',
    'configuration_version',
] as $needle) {
    if (!str_contains($store, $needle)) {
        throw new RuntimeException('Sales V0.7.1: configuration ownership guard is missing: ' . $needle);
    }
}

$migration = (string) file_get_contents(
    $root . '/app/migrations/20260910_000030_sales_v071_configuration_ownership.sql'
);
foreach ([
    'cos_configuration_revisions',
    "'SYSTEM', 'ADMIN'",
    "'HUMAN_ONLY'",
    'configuration_version',
    'domain_name',
] as $needle) {
    if (!str_contains($migration, $needle)) {
        throw new RuntimeException('Sales V0.7.1 migration is missing: ' . $needle);
    }
}

$capabilities = (string) file_get_contents($root . '/app/Domains/Sales/Model/SalesCapability.php');
foreach ([
    'sales.workspace.use',
    'sales.director.view',
    'sales.admin.pipeline.manage',
    'sales.admin.rules.manage',
    'sales.admin.agents.manage',
    'sales.admin.policies.manage',
    'sales.admin.teams.manage',
    'sales.admin.integrations.manage',
    'sales.admin.audit.view',
] as $capability) {
    if (!str_contains($capabilities, $capability)) {
        throw new RuntimeException('Sales V0.7.1 capability is missing: ' . $capability);
    }
}

echo "Sales V0.7.1 configuration ownership contract passed.\n";
