<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dashboard = (string) file_get_contents($root . '/symfony/templates/experience/sales/dashboard.html.twig');
foreach (['Sales Dashboard', '<twig:CosMoneyMetric', '<twig:CosTrendMetric', '<twig:CosEntityListItem', '/sales/leads'] as $text) {
    if (!str_contains($dashboard, $text)) throw new RuntimeException('Canonical Dashboard missing ' . $text);
}
$leads = (string) file_get_contents($root . '/symfony/templates/experience/sales/leads.html.twig');
foreach (['data-lead-id', 'data-sales-lead-status', 'data-sales-lead-owner', 'data-sales-lead-deal', 'data-sales-lead-followup'] as $marker) {
    if (!str_contains($leads, $marker)) throw new RuntimeException('Canonical Lead Inbox missing ' . $marker);
}

$migration=(string)file_get_contents($root.'/app/migrations/20260904_000021_sales_runtime_workspace.sql');
foreach (['sales_pipelines','sales_pipeline_stages','sales_pipeline_transitions','sales_communications','cos_action_outcomes','sales_metric_snapshots'] as $table) if (!str_contains($migration,$table)) throw new RuntimeException('Migration missing '.$table);

echo "Sales workspace UI and schema contract passed.\n";
