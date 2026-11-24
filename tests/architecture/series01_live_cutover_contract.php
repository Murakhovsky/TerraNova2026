<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$cmd=(string)file_get_contents($root.'/symfony/src/Command/ProductManagementSeries01CutoverCommand.php');
$script=(string)file_get_contents($root.'/bin/series01-live-bootstrap.sh');
$workflow=(string)file_get_contents($root.'/.github/workflows/cos-series01-live-bootstrap.yml');
$assert=static function(bool $ok,string $reason):void {
    if(!$ok)throw new RuntimeException('SERIES01 live cutover contract: '.$reason);
};
foreach([
    "self::TENANT",'ENABLE_PRODUCT_MANAGEMENT_DEFAULT',
    'STAGE_SERIES01_PREVIEW_ONLY','COS_PRODUCT_SERIES01_OPERATOR_ENABLED',
    'ModuleControlService','CsvProductImportService','ModuleReadinessDiagnostic',
    'series01-preview-',"'counts'",'READY','PREVIEW_STAGED_AWAITING_INDEPENDENT_APPROVAL',
    'canonical_products_written',
] as $item) $assert(str_contains($cmd,$item),'cutover command is not guarded by '.$item);
$assert(!str_contains($cmd,'applyApproved(')
    &&!str_contains($cmd,'ProductImportApplyHandler')
    &&!str_contains($cmd,'createProduct('),
    'bootstrap must never create native Products without independent Approval');
foreach([
    'series01-tenant-default-bootstrap.done',
    '.cos-deployment-attestation.json',
    'cos:product:series01',
    'ENABLE_PRODUCT_MANAGEMENT_DEFAULT',
    'STAGE_SERIES01_PREVIEW_ONLY',
    'shopify-series01-operator.sh default provision',
    'shopify-series01-operator.sh default pull',
    'SERIES01_AWAITING_INDEPENDENT_COS_PLAN_AND_ACTION_APPROVAL',
] as $item)$assert(str_contains($script,$item),'missing bootstrap invariant '.$item);
foreach([
    'workflow_run:','workflows: [Runtime]',
    "github.event.workflow_run.event == 'push'",
    "github.event.workflow_run.head_branch == 'main'",
    "github.event.workflow_run.conclusion == 'success'",
    'environment: AWS TN2026',
    'git rev-parse HEAD','StrictHostKeyChecking=yes',
    'series01-live-bootstrap.sh',
] as $item)$assert(str_contains($workflow,$item),'missing protected workflow gate '.$item);
$assert(!str_contains($workflow,'workflow_dispatch:')&&!str_contains($workflow,'schedule:'),
    'one-shot cutover only follows successful main deployment');
echo "SERIES01_LIVE_CUTOVER_CONTRACT_OK\n";
