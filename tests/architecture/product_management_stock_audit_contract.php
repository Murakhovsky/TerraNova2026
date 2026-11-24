<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$audit=(string)file_get_contents($root.'/app/Domains/ProductManagement/Infrastructure/Persistence/MySqlStockReconciliationAudit.php');
$command=(string)file_get_contents($root.'/symfony/src/Command/ProductManagementStockAuditCommand.php');
$services=(string)file_get_contents($root.'/symfony/config/services.yaml');
$workflow=(string)file_get_contents($root.'/.github/workflows/runtime.yml');
$integration=(string)file_get_contents($root.'/tests/integration/product_management_stock_concurrency_mysql.php');

$assert=static function(bool $ok,string $message):void {
    if (!$ok)throw new RuntimeException('E5 read-only audit contract: '.$message);
};
$assert(str_contains($audit,'function audit(')
    && str_contains($audit,'WITH stock_pairs AS')
    && str_contains($audit,'FROM pm_stock_movements WHERE organization_id=?')
    && str_contains($audit,"FROM pm_stock_reservations WHERE organization_id=? AND status='active'")
    && str_contains($audit,'INCOMPLETE')
    && str_contains($audit,'DRIFT')
    && str_contains($audit,"'automatic_repair_authorized'=>false"),
    'Audit must compare source aggregates, fail closed on truncated output and forbid repair.');
$assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|TRUNCATE)\b/i',$audit)
    && !str_contains($audit,'beginTransaction('),
    'Read-only Audit must not create or mutate any domain record.');
$assert(str_contains($command,"COS_PRODUCT_STOCK_AUDIT_ENABLED")
    && str_contains($command,'product_management')
    && str_contains($command,'Command::SUCCESS:Command::FAILURE')
    && !str_contains($command,'ProductManagementService'),
    'Server operator gate, tenant module ownership and failure status are mandatory.');
$assert(str_contains($services,'Domains\ProductManagement\Infrastructure\Persistence\MySqlStockReconciliationAudit:')
    && str_contains($workflow,'Product Management E5 concurrent Stock MySQL and reconciliation')
    && str_contains($integration,"proc_open(")
    && str_contains($integration,"'DRIFT'")
    && str_contains($integration,"'INCOMPLETE'"),
    'Live MySQL concurrency/audit CI wiring missing.');
echo "Product Management E5 read-only audit architecture PASSED.\n";
