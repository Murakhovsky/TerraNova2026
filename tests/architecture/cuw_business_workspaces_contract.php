<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$get=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$source=$get('symfony/src/Web/Experience/Unified/UnifiedBusinessWorkspaceController.php');
$read=$get('symfony/src/Web/Experience/Unified/Business/WorkspaceCapabilityRead.php');
$confirm=$get('symfony/src/Web/Experience/Unified/Business/SalesOrderConfirmationController.php');
foreach (['providers()->workspaces()','BusinessRecordProvider','recordPage','safePath','selectedKey','WorkspaceCompositionResolver','no-store, private'] as $part) {
    if(!str_contains($source,$part))throw new RuntimeException('Missing workspace boundary '.$part);
}
foreach (['TenantPermissions::MANAGE',"'read_only'","'query'","'trusted_read'",'CanonicalCapabilityGateway'] as $part) {
    if(!str_contains($read,$part))throw new RuntimeException('Missing read boundary '.$part);
}
foreach (['SessionCsrfValidator','UIActionResolver','expected_revision','idempotency_key','sales.order.lifecycle.read',"'human_ui'","'unknown'"] as $part) {
    if(!str_contains($confirm,$part))throw new RuntimeException('Missing command fence '.$part);
}
$draft=$get('symfony/src/Web/Experience/Unified/Business/FinanceBudgetDraftController.php');
foreach (['SessionCsrfValidator','UIActionResolver','marketing.campaign.get','finance.budget.envelope.get',"'human_ui'","'DRAFT'","'reserved_minor'","'committed_minor'"] as $part) {
    if(!str_contains($draft,$part))throw new RuntimeException('Missing Finance draft fence '.$part);
}
foreach (['Finance','Marketing','ProductManagement','Property','Sales'] as $domain) {
    $manifest=require $root.'/app/Domains/'.$domain.'/module.php';
    $services=$manifest['contributions']['extension_services'];
    if(empty($services['web.workspace'])||empty($services['web.entity_links']))throw new RuntimeException('Unregistered provider '.$domain);
    $provider=$get('symfony/src/Web/Experience/Extension/Provider/'.$domain.'WebProvider.php');
    foreach (['WorkspaceProviderInterface','EntityLinkProviderInterface','BusinessRecordProvider','WorkspaceCapabilityRead','function record('] as $part) {
        if(!str_contains($provider,$part))throw new RuntimeException('Missing provider contract '.$domain.': '.$part);
    }
    foreach (['PDO','Doctrine\\','SELECT ','INSERT ','/api/v1/'] as $bad) {
        if(str_contains($provider,$bad))throw new RuntimeException('Provider bypass '.$domain.': '.$bad);
    }
}
if(!str_contains($get('symfony/config/routes/cuw_sales_order.yaml'),'methods: [POST]'))throw new RuntimeException('Command must be POST only.');
echo "CUW-E registered providers and canonical human command boundary PASS.\n";
