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
  'PROVISION_READ_ONLY','PULL_READ_ONLY','order-sandbox','VERIFY_TEST_ORDER_READ_ONLY',
] as $token) if (!str_contains($script,$token)) throw new RuntimeException('Missing series guard: '.$token);
if (str_contains($script,'lamps-6eey3z3e') || str_contains($workflow,'lamps-6eey3z3e')) {
  throw new RuntimeException('SERIES01 must never use Lamps pilot connection.');
}
foreach (['workflow_dispatch:','AWS TN2026',"github.ref == 'refs/heads/main'",'StrictHostKeyChecking=yes','order-sandbox'] as $token) {
  if (!str_contains($workflow,$token)) throw new RuntimeException('Operator workflow not guarded: '.$token);
}
if (str_contains($workflow,'push:') || str_contains($workflow,'schedule:')) {
  throw new RuntimeException('Shopify connection operations cannot be automatically scheduled.');
}
echo "SHOPIFY_SERIES01_OPERATOR_CONTRACT_OK\n";
