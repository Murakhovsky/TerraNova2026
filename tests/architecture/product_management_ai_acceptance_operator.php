<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$workflow=file_get_contents($root.'/.github/workflows/cos-product-p6-live-acceptance.yml');
$compose=file_get_contents($root.'/docker-compose.yml');
$command=file_get_contents($root.'/symfony/src/Command/ProductManagementAiAcceptanceCommand.php');
$service=file_get_contents($root.'/symfony/src/Persistence/ProductManagement/ProductAiAcceptanceService.php');
foreach(['workflow_dispatch:','refs/heads/main','environment: AWS TN2026','APPROVED_ORG','"$ORG" != default',
    '.cos-deployment-attestation.json','cos-kernel-worker-1','cos:product:ai-acceptance','product-p6-live-evidence','"NATIVE_AI_READBACK_PASS"'] as $required){
    if(!str_contains($workflow,$required))throw new RuntimeException('Missing supervised P6 fence '.$required);
}
foreach(['  push:', '  pull_request:', '  schedule:', '  workflow_run:', 'docker compose up','cos:schema:migrate'] as $forbidden){
    if(str_contains($workflow,$forbidden))throw new RuntimeException('Unexpected automated P6 activation '.$forbidden);
}
foreach(['COS_AI_CAPABILITY_INTENT_SECRET','COS_AUTOMATION_TENANT_ORGANIZATION','COS_FEDERATION_CANONICAL_ORGANIZATION'] as $key){
    if(!str_contains($compose,$key.': ${'.$key.':-}'))throw new RuntimeException('Missing default-off env passthrough '.$key);
}
foreach(['COS_PRODUCT_AI_ACCEPTANCE_ENABLED','COS_PRODUCT_AI_ACCEPTANCE_ENVIRONMENT','COS_PRODUCT_AI_ACCEPTANCE_ORGANIZATION'] as $key){
    if(!str_contains($command,$key))throw new RuntimeException('Missing CLI opt in '.$key);
}
foreach(['submitProduct(', 'inspectNativeForAudit(', 'observeRunForAudit(', 'No uniquely attributable live provider usage.'] as $needle){
    if(!str_contains($service,$needle))throw new RuntimeException('Missing P6 evidence fence '.$needle);
}
foreach(['->approve(', '->execute(', '->start(', '->adopt(', '->recordEvaluation('] as $needle){
    if(str_contains($service,$needle))throw new RuntimeException('P6 operator acquired execution authority '.$needle);
}
echo "Product P6 protected operator and canonical proposal-only authority PASS.\n";
