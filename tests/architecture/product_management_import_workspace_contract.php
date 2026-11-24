<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$controller=$read('symfony/src/Web/ProductManagement/ProductImportController.php');
$twig=$read('symfony/templates/experience/product_management/import.html.twig');
$service=$read('app/Domains/ProductManagement/Application/Service/CsvProductImportService.php');
$plans=$read('symfony/src/Persistence/ProductManagement/ProductImportPlanProposalService.php');
$routes=$read('symfony/config/routes.yaml');
$provider=$read('symfony/src/Web/Experience/Extension/Provider/ProductManagementWebProvider.php');
$workflow=$read('.github/workflows/runtime.yml');
$assert=static function(bool $valid,string $reason):void{
    if(!$valid)throw new RuntimeException('PM CSV Workspace: '.$reason);
};
foreach(['CanonicalCapabilityGateway','SessionCsrfValidator','ActiveModuleResolver',
    'TenantPermissions::MANAGE','ProductImportPlanProposalService',
    'product_management.import.preview','product_management.import.batch.get',
    "'human_ui'","'trusted_read'",'hash_equals','rows_digest',
    '$this->plans->propose('] as $piece) {
    $assert(str_contains($controller,$piece),'Missing approval-safe CSV operation: '.$piece);
}
foreach(['applyApproved(','createProduct(', 'PDO', '->prepare(', 'ShopifyReadClient','requestApproval('] as $forbidden){
    $assert(!str_contains($controller,$forbidden),'Direct unapproved CSV write or Approval path: '.$forbidden);
}
$assert(str_contains($service,"'goal_id'=>\$batch['goal_id']??null")
    &&str_contains($service,"'plan_id'=>\$batch['plan_id']??null"),
    'Plan proposal must be independently visible in tenant-scoped batch report.');
$assert(str_contains($plans,'independent_plan_and_action_approvals')
    &&str_contains($twig,'/workspace/goals')
    &&substr_count($twig,'name="csrf_token"')>=2,
    'Independent human approval handoff missing.');
$assert(str_contains($provider,"'/product-management/import'")
    &&str_contains($routes,'cos_product_management_import_propose:')
    &&str_contains($workflow,'php tests/architecture/product_management_import_workspace_contract.php'),
    'CSV Workspace must be extension/route/CI registered.');
echo "Product Management governed CSV Workspace architecture passed.\n";
