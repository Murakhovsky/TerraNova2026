<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$controller=(string)file_get_contents($root.'/symfony/src/Http/Api/V1/Controller/ProductManagementImportPlanController.php');
$routes=(string)file_get_contents($root.'/symfony/config/routes.yaml');
$proposal=(string)file_get_contents($root.'/symfony/src/Persistence/ProductManagement/ProductImportPlanProposalService.php');

foreach([
    'TenantContextProviderInterface','SessionCsrfValidator','ActiveModuleResolver',
    'ProductImportPlanProposalService','isManager()','TenantPermissions::MANAGE',
    'product_management','batch_digest','rows_digest',
    "preg_match('/^[a-f0-9]{64}$/D'","->propose($tenant,$batchId,$digest,$rowsDigest)",
] as $token) {
    if(!str_contains($controller,$token))
        throw new RuntimeException('Unsafe Product CSV proposal endpoint missing: '.$token);
}
if(!str_contains($routes,'cos_product_management_import_plan_propose:')
    ||!str_contains($routes,'/api/v1/product-management/imports/{batchId}/propose')
    ||!str_contains($routes,'ProductManagementImportPlanController::propose')) {
    throw new RuntimeException('Native COS Product CSV proposal route missing.');
}
if(!str_contains($proposal,'independent_plan_and_action_approvals')
    ||!str_contains($proposal,'->createGoal(')
    ||!str_contains($proposal,'->proposePlan(')
    ||str_contains($controller,'approvePlan(')
    ||str_contains($controller,'approveAction(')
    ||str_contains($controller,'applyApproved(')) {
    throw new RuntimeException('Product CSV proposal must use canonical Federation Goal/Plan and not approve itself.');
}
echo "PRODUCT_CSV_AUTHENTICATED_GOAL_PLAN_PROPOSAL_ROUTE_OK\n";
