<?php
declare(strict_types=1);

/** Static contract: SERIES01 operator is isolated, non-ambient and manual. */
$root=dirname(__DIR__,2);
$script=file_get_contents($root.'/bin/shopify-series01-operator.sh');
$workflow=file_get_contents($root.'/.github/workflows/cos-shopify-series01-operator.yml');
if (!is_string($script) || !is_string($workflow)) throw new RuntimeException('Missing Shopify operator assets.');
foreach ([
  'set -Eeuo pipefail','/etc/cos/shopify.env','stat -c', '0:600',
  'e2rnbw-mr.myshopify.com','SHOPIFY_SHOP_DOMAIN',
  'SHOPIFY_CLIENT_ID','SHOPIFY_CLIENT_SECRET',
  'cos:product:shopify','COS_SHOPIFY_OPERATOR_ENABLED=1',
  'PROVISION_READ_ONLY','PULL_READ_ONLY','order-sandbox','VERIFY_TEST_ORDER_READ_ONLY','order-lifecycle-sandbox','VERIFY_TEST_LIFECYCLE_READ_ONLY',
  'SERIES01_LIFECYCLE_DEPLOYED_ATTESTATION_MISMATCH','SERIES01_LIFECYCLE_DEPLOYED_CODE_MISMATCH',
  'SERIES01_LIFECYCLE_CONTAINER_CHANGED','cli_source_verified_before_and_after',
] as $token) if (!str_contains($script,$token)) throw new RuntimeException('Missing series guard: '.$token);
if (str_contains($script,'lamps-6eey3z3e') || str_contains($workflow,'lamps-6eey3z3e')) {
  throw new RuntimeException('SERIES01 must never use Lamps pilot connection.');
}
foreach (['workflow_dispatch:','AWS TN2026',"github.ref == 'refs/heads/main'",'StrictHostKeyChecking=yes','order-sandbox','order-lifecycle-sandbox','expected_sha:','EXPECTED_CODE_SHA','EXPECTED_ROUTES_SHA','actions/upload-artifact@v4'] as $token) {
  if (!str_contains($workflow,$token)) throw new RuntimeException('Operator workflow not guarded: '.$token);
}
if (str_contains($workflow,'push:') || str_contains($workflow,'schedule:')) {
  throw new RuntimeException('Shopify connection operations cannot be automatically scheduled.');
}
$pre=strpos($script,"\n  verify_lifecycle_deployment\nfi");
$read=strpos($script,'RESULT="$(run_read_operator)"');
$post=strpos($script,"\n  verify_lifecycle_deployment\n  # Publish");
if($pre===false||$read===false||$post===false||!($pre<$read&&$read<$post)) {
  throw new RuntimeException('Lifecycle evidence requires both deployment checks around provider read.');
}
echo "SHOPIFY_SERIES01_OPERATOR_CONTRACT_OK\n";
