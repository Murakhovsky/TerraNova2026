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
// Docker runtime excludes fixture files. The workflow must attest the exact
// checked-out CSV bytes before streaming into the CLI over SSH/stdin.
$csv=(string)file_get_contents($root.'/resources/pilots/series01/approved-import-candidate.csv');
$fixtureHash=hash('sha256',$csv);
$assert($fixtureHash==='f8122e58f81f61d112be1bd0db0e5ae8f20fda5d6dcce57eab4fa69a0e2a058c',
    'SERIES01 fixture content unexpectedly changed');
$assert(str_contains($cmd,'stream_get_contents(STDIN,12001)')
    &&str_contains($cmd,$fixtureHash)
    &&!str_contains($cmd,'file_get_contents($path)'),
    'PHP preview must consume exact pinned input bytes, not runtime fixture path');
$assert(str_contains($script,'docker exec -i -e COS_PRODUCT_SERIES01_OPERATOR_ENABLED=1'),
    'Bootstrap must pass CSV through docker exec stdin');
$assert(str_contains($workflow,'"$remote" < "$fixture"')
    &&str_contains($workflow,'sha256sum "$fixture"'),
    'Workflow must pipe pinned source-controlled CSV over SSH');

// The pilot audit must be read-only and pin both the imported rowset and
// independent native Product IDs. It is safe to call after bootstrap.
foreach ([
    "'audit'=>$this->audit($output)",
    'ca2613c17efed1aaf6aa9c20178a70b1',
    'a48b950feb912f4b4065cff8dcf85943c63a525453098f186e199a7c400a0d00',
    '->report(self::TENANT,self::BATCH)',
    '->getProduct(self::TENANT,$id)',
    'cos_federation_plans',
    'cos_workflow_runs',
    'workflow_receipt_independently_attested',
] as $item) $assert(str_contains($cmd,$item),'read-only native audit missing '.$item);
$assert(substr_count($script,'audit --organization=default')===2,
    'both first and repeat bootstrap must inspect native batch without mutations');
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
