<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); };
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException('Missing file: ' . $path);
    return $content;
};
$contains = static function (string $path, array $needles, string $label) use ($read, $assert): void {
    $content = $read($path);
    foreach ($needles as $needle) $assert(str_contains($content, $needle), 'Sales V0.7.3 ' . $label . ' missing: ' . $needle);
};

$contains('app/Domains/Sales/Automation/Rule/SalesRuleDefinitionCatalog.php', [
    'Deal Created','Deal Stage Changed','Incoming Message','Call Completed','Meeting Completed','Follow-up Overdue','No Activity Detected','Deal Stuck','Lead Created','Lead Qualified',
    'deal.stage_code','deal.pipeline_id','deal.value','deal.priority','deal.risk','deal.days_without_activity','deal.owner_id','deal.source','lead.status','lead.source','activity.type','activity.status',
    "'IN', 'NOT_IN', 'EXISTS', 'NOT_EXISTS'", "'minutes'", "'hours'", "'days'",
    'Run Sales Intelligence','Create Task','Create Follow-up','Schedule Follow-up','Send Message','Request Document','Schedule Meeting','Request Manager Review','Change Stage','Assign Owner',
    'assertKeys(', 'Unsupported Sales action', 'Unsupported fact for selected trigger', 'Advanced rule DSL contains unsupported condition structure',
], 'rule definition catalog');
$contains('app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlSalesRuleAdministration.php', [
    'implements SalesRuleAdministrationInterface', "domain_name = 'sales'", "ownership='ADMIN'", 'CONFIGURATION_CONFLICT',
    "status='DRAFT'", "'ACTIVE'", "'DISABLED'", "'ARCHIVED'", 'restoreSystem(', 'cos_configuration_revisions', "configuration_type='RULE'",
    'dryRun(', 'matched_deals', 'would_require_approval', 'would_be_denied', "'mutations' => 0", 'policyEngine->evaluate', 'actionsFromEffect',
    'Disable an active rule before editing it.',
], 'rule administration');
$contains('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesRuleContextProvider.php', [
    'days_without_activity','owner_id','value','activityContext','source, c.last_activity_at',
], 'runtime facts');
$contains('app/Interfaces/Web/Routing/SalesRoutes.php', [
    '/sales/admin/rules', '/api/sales/admin/rules/catalog', '/dry-run', '/restore-system', '/revisions',
], 'routes');
$contains('app/Interfaces/Api/Controller/SalesAdminRuleController.php', [
    'createAction','updateAction','activateAction','disableAction','archiveAction','dryRunAction','restoreSystemAction','revisionsAction',
], 'admin API');
$contains('app/Interfaces/Web/View/sales_admin/rule.phtml', [
    'WHEN → IF → THEN','Basic Mode','Advanced Mode','Test Rule','data-rule-conditions','data-rule-actions','Revision history',
], 'editor UI');
$contains('frontend/features/sales/rule-editor.js', [
    'data-rule-add-condition','data-rule-add-action','configuration_version','dry-run','No business state changed',
], 'editor JS');
$contains('app/Bootstrap/SalesRuleServices.php', [
    "'salesRuleAdministration'", 'MysqlSalesRuleAdministration','salesRuleDefinitionCatalog','cosPolicyEngine',
], 'composition root');

$tableOwnership = $read('app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlSalesRuleAdministration.php');
$assert(str_contains($tableOwnership, 'UPDATE cos_rules') && str_contains($tableOwnership, 'INSERT INTO cos_rules'), 'Kernel-owned rule persistence must live in Platform Infrastructure.');
foreach ([
    'app/Domains/Sales/Automation/Rule/SalesRuleDefinitionCatalog.php',
    'app/Domains/Sales/Application/Contract/SalesRuleAdministrationInterface.php',
] as $file) {
    $source = $read($file);
    $assert(!str_contains($source, 'PDO'), $file . ' must not persist Kernel rule tables directly.');
}

$bootstrapCatalog = $read('app/Domains/Sales/Automation/Rule/SalesRuleCatalog.php');
$assert(str_contains($bootstrapCatalog, 'SalesEventType::NO_ACTIVITY_DETECTED'), 'Existing SalesRuleCatalog bootstrap defaults must remain available for provisioning.');
$assert(!str_contains($tableOwnership, 'SalesRuleCatalog'), 'Admin editor must not depend on PHP bootstrap rule catalog.');

foreach ([
    'app/Domains/Sales/Application/Contract/SalesRuleAdministrationInterface.php',
    'app/Domains/Sales/Automation/Rule/SalesRuleDefinitionCatalog.php',
    'app/Infrastructure/Platform/Persistence/MySql/Configuration/MysqlSalesRuleAdministration.php',
    'app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesRuleContextProvider.php',
    'app/Bootstrap/SalesRuleServices.php',
    'app/Interfaces/Api/Controller/SalesAdminRuleController.php',
    'app/Interfaces/Web/Controller/SalesAdminController.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Web/View/sales_admin/rules.phtml',
    'app/Interfaces/Web/View/sales_admin/rule.phtml',
] as $file) {
    $output = []; $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed for ' . $file . ': ' . implode("\n", $output));
}

echo "Sales V0.7.3 Business Rule Editor contract passed.\n";
