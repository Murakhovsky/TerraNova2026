<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$read=static fn(string $f):string=>(string)file_get_contents($root.'/'.$f);
$def=require $root.'/app/Domains/ProductManagement/module.php';
$match=array_values(array_filter($def['contributions']['capability_contracts'],
    static fn(array $x):bool=>($x['id']??null)==='product_management.shopify.import.apply'));
if(count($match)!==1)throw new RuntimeException('Exactly one canonical Shopify import Action contract required.');
$cap=$match[0];
foreach(['execution_binding'=>'action:product_management.shopify.import.apply',
    'approval_policy'=>'required','idempotency'=>'required'] as $field=>$want) {
    if(($cap[$field]??null)!==$want)throw new RuntimeException('Shopify approved capability missing '.$field);
}
if(($cap['retry_policy']['max_attempts']??null)!==1
    ||!in_array('product_management.shopify.import.apply',$def['contributions']['capabilities'],true)) {
    throw new RuntimeException('Shopify Action may not blindly retry or lack canonical registration.');
}
$handler=$read('app/Domains/ProductManagement/Automation/Action/ShopifyImportApplyHandler.php');
$module=$read('app/Domains/ProductManagement/Bootstrap/ProductManagementDomainModule.php');
$worker=$read('app/Domains/ProductManagement/Infrastructure/Shopify/ShopifyImportApplyService.php');
$planner=$read('symfony/src/Persistence/ProductManagement/ShopifyImportPlanProposalService.php');
$outcome=$read('symfony/src/Persistence/ProductManagement/ShopifyImportRunOutcomeProvider.php');
$services=$read('symfony/config/services.yaml');
foreach(['ActionStatus::Running','APPROVAL_REQUIRED','IdempotentExternalActionHandlerInterface',
    '$this->modules->isEnabled('] as $needle) {
    if(!str_contains($handler,$needle))throw new RuntimeException('Shopify Action missing '.$needle);
}
foreach(['PolicyDecision::ApprovalRequired','ShopifyImportApplyHandler::TYPE',
    '$shopifyHandler'] as $needle) {
    if(!str_contains($module,$needle))throw new RuntimeException('Shopify Module missing '.$needle);
}
foreach(['GET_LOCK(','RELEASE_LOCK(','state=\'blocked\'',
    'ProductManagementService','PricingService','verifyApplied(',
    'pm_shopify_import_step_receipts','ShopifyImportCandidateNormalizer::fingerprint'] as $needle) {
    if(!str_contains($worker,$needle))throw new RuntimeException('Shopify writer missing '.$needle);
}
if(str_contains($worker,'ShopifyReadClient')||str_contains($worker,'curl_')
    ||str_contains($worker,'inventorySetQuantities')||str_contains($worker,'postMovement(')) {
    throw new RuntimeException('Shopify approved native writer has external/stock side effects.');
}
foreach(['FederationGoalStore','GoalSpecification',
    'independent_plan','independent_action'] as $needle) {
    if(!str_contains($planner,$needle))throw new RuntimeException('Shopify Plan missing '.$needle);
}
foreach(['attestForAudit','verifyApplied','WorkflowRunIdentity'] as $needle) {
    if(!str_contains($outcome,$needle))throw new RuntimeException('Shopify independent outcome missing '.$needle);
}
if(!str_contains($services,'ShopifyImportRunOutcomeProvider')
    ||!str_contains($services,'ShopifyImportApplyService')) {
    throw new RuntimeException('Shopify service graph lacks writer or independent evidence.');
}
foreach(['shopify-import-apply-input.schema.json','shopify-import-apply-output.schema.json'] as $path) {
    $json=json_decode($read('resources/contracts/product-management/'.$path),true,flags:JSON_THROW_ON_ERROR);
    if(($json['type']??null)!=='object')throw new RuntimeException('Invalid Shopify canonical Action contract.');
}
echo "Product Management Shopify approved Action/Plan/Outcome contract passed.\n";
