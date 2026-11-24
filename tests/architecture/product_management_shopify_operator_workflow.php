<?php
declare(strict_types=1);

$root=dirname(__DIR__,2);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$command=$read('symfony/src/Command/ProductManagementShopifyOperatorCommand.php');
$wrapper=$read('bin/shopify-lamps-operator.sh');
$workflow=$read('.github/workflows/cos-shopify-lamps-operator.yml');
$runtime=$read('.github/workflows/runtime.yml');
$assert=static function(bool $valid,string $description):void {
    if (!$valid) throw new RuntimeException('Shopify operator workflow: '.$description);
};
foreach([
    'if ($action===\'preflight\')',
    '$this->modules->isInstalled(',
    '$this->modules->isCurrent(',
    '$this->modules->isConfiguredEnabled(',
    '$this->modules->isEnabled(',
    'MODULE_NOT_READY',
    'COS_SHOPIFY_OPERATOR_ENABLED',
] as $expected) {
    $assert(str_contains($command,$expected),'Missing tenant diagnostic: '.$expected);
}
$assert(str_contains($wrapper,"  preflight)") && str_contains($wrapper,'COS_SHOPIFY_OPERATOR_ENABLED=1'),
    'Supervisor shell gate or preflight missing.');
foreach([
    'workflow_dispatch:',
    "github.ref == 'refs/heads/main'",
    'environment: AWS TN2026',
    'preflight|provision',
    'pull|candidates|status',
    '^[a-f0-9]{32}$',
    'bin/shopify-lamps-operator.sh',
    'cancel-in-progress: false',
] as $expected) {
    $assert(str_contains($workflow,$expected),'Workflow lacks read-only fence: '.$expected);
}
foreach(['schedule:', 'push:', 'stage|audit', 'SHOPIFY_CLIENT_SECRET:', 'SHOPIFY_CLIENT_ID:',
    'set -x', '--confirm=STAGE_NO_WRITES'] as $forbidden) {
    $assert(!str_contains($workflow,$forbidden),'Unexpected automated write or secret exposure: '.$forbidden);
}
$assert(str_contains($runtime,'php tests/architecture/product_management_shopify_operator_workflow.php'),
    'Architecture guard must be run in the Runtime CI.');
echo "Shopify supervised operator workflow architecture passed.\n";
