<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if ($content === false) throw new RuntimeException('Missing file: ' . $path);
    return $content;
};
$contains = static function (string $path, array $needles, string $label) use ($read, $assert): void {
    $content = $read($path);
    foreach ($needles as $needle) $assert(str_contains($content, $needle), 'Sales V0.7.3 ' . $label . ' missing: ' . $needle);
};

$contains('app/Domains/Sales/Application/Contract/SalesPipelineGovernanceInterface.php', [
    'cloneToDraft(', 'revisions(',
], 'governance contract');
$contains('app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesPipelineGovernance.php', [
    'implements SalesPipelineGovernanceInterface',
    '"DRAFT"',
    '$stageMap',
    'sales_pipeline_transitions',
    'sales_lost_reasons',
    'cos_configuration_revisions',
    '"PROVISION"',
    'domain_name = "sales"',
    'source_pipeline_id',
], 'governance persistence');
$contains('app/Bootstrap/SalesServices.php', [
    'MysqlSalesPipelineGovernance', "'salesPipelineGovernance'",
], 'composition root');
$contains('app/Interfaces/Api/Controller/SalesAdminPipelineController.php', [
    'SalesPipelineGovernanceInterface', 'cloneAction', 'revisionsAction', "'salesPipelineGovernance'",
], 'admin API');
$contains('app/Interfaces/Web/Routing/SalesRoutes.php', [
    '/clone', '/revisions',
], 'routes');
$contains('app/Interfaces/Web/View/sales_admin/pipelines.phtml', [
    'Clone to draft', 'data-redirect-root',
], 'clone UI');
$contains('app/Interfaces/Web/View/sales_admin/pipeline.phtml', [
    'Revision history', 'data-revisions', 'before_payload', 'after_payload',
], 'revision UI');

$workspace = $read('app/Interfaces/Api/Controller/SalesWorkspaceActionsController.php');
$assert(str_contains($workspace, 'Pending approvals must be rejected through ApprovalService'), 'Sales V0.6.2 consistency boundary is still undocumented.');
$assert(str_contains($workspace, 'ActionStatus::PendingApproval'), 'Sales V0.6.2 pending-approval dismiss guard is missing.');
$assert(str_contains($workspace, "'Pending approval must be rejected through its approval decision.'"), 'Sales V0.6.2 manager guidance is missing.');

foreach ([
    'app/Domains/Sales/Application/Contract/SalesPipelineGovernanceInterface.php',
    'app/Domains/Sales/Infrastructure/Persistence/MySql/MysqlSalesPipelineGovernance.php',
    'app/Bootstrap/SalesServices.php',
    'app/Interfaces/Api/Controller/SalesAdminPipelineController.php',
    'app/Interfaces/Api/Controller/SalesWorkspaceActionsController.php',
    'app/Interfaces/Web/Routing/SalesRoutes.php',
    'app/Interfaces/Web/View/sales_admin/pipelines.phtml',
    'app/Interfaces/Web/View/sales_admin/pipeline.phtml',
] as $file) {
    $output = [];
    $code = 0;
    exec('php -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $output, $code);
    $assert($code === 0, 'PHP syntax failed for ' . $file . ': ' . implode("\n", $output));
}

echo "Sales V0.7.3 governance contract passed; V0.6.2 dismiss consistency debt is closed.\n";
