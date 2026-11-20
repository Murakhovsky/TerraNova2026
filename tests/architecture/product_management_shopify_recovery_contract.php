<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$writer=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyImportApplyService.php');
$audit=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyImportRecoveryAudit.php');
$command=$read('symfony/src/Command/ProductManagementShopifyOperatorCommand.php');
$services=$read('symfony/config/services.yaml');
$workflow=$read('.github/workflows/runtime.yml');
$claimMigration=$read('app/migrations/20261120_000161_product_management_shopify_identity_claim.sql');
$planner=$read('symfony/src/Persistence/ProductManagement/ShopifyImportPlanProposalService.php');
$manifest=$read('app/Domains/ProductManagement/module.php');
$assert=static function(bool $ok,string $why):void {
    if(!$ok)throw new RuntimeException('Shopify recovery architecture: '.$why);
};
foreach([
    "if (\$scope['state']==='applying')",
    "SET state='blocked'",
    'independent audit and human reconciliation required',
    'GET_LOCK(',
    'RELEASE_LOCK(',
] as $expected) {
    $assert(str_contains($writer,$expected),'Missing fail-closed Shopify crash fence: '.$expected);
}
foreach([
    "organization_id=? AND connection_id=? AND preview_id=?",
    "pm_shopify_import_step_receipts",
    "pm_operation_receipts",
    "pm_base_prices",
    "automatic_replay_authorized'=>false",
    "independent_applied_evidence",
    "source_hash_matches_current",
    "manual_reconciliation_required",
] as $required) {
    $assert(str_contains($audit,$required),'Recovery audit lacks independent evidence: '.$required);
}
foreach(['INSERT INTO','UPDATE pm_','DELETE FROM','applyApproved(','createProduct(','recordMovement('] as $forbidden) {
    $assert(!str_contains($audit,$forbidden),'Read-only recovery audit acquired mutation path: '.$forbidden);
}
$assert(str_contains($command,"if (\$action==='audit')")
    &&str_contains($command,'COS_SHOPIFY_OPERATOR_ENABLED')
    &&str_contains($command,'$this->modules->isEnabled('),
    'Operator recovery audit must be explicitly armed and tenant gated.');
$assert(str_contains($services,'ShopifyImportRecoveryAudit')
    &&str_contains($workflow,'product_management_shopify_crash_recovery_mysql.php'),
    'Recovery audit service and MySQL acceptance must be registered.');

$assert(str_contains($claimMigration,'GENERATED ALWAYS AS')
    &&str_contains($claimMigration,'uq_pm_shopify_one_planned_identity')
    &&str_contains($claimMigration,'connection_id,planned_product_gid')
    &&str_contains($planner,'already claimed by a different planned preview')
    &&str_contains($manifest,'20261120_000161_product_management_shopify_identity_claim.sql'),
    'Shopify Product external identity must allow at most one approved import Plan.');
echo "Product Management Shopify crash recovery architecture passed.\n";
