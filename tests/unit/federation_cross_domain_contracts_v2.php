<?php
declare(strict_types=1);

use Kernel\Module\CrossDomainContract;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$raw = [
    'contract' => 'Domains\\Sales\\Application\\Contract\\SalesWriteServiceFactoryInterface',
    'role' => 'requires', 'counterpart' => 'sales', 'kind' => 'synchronous_port',
    'version' => '2.1.0', 'supported_version_range' => '>=2.0.0 <3.0.0',
    'input_schema' => 'SalesWriteInputV2', 'output_schema' => 'SalesWriteResultV2',
    'failure_semantics' => 'typed_failure', 'idempotency_semantics' => 'consumer_scoped_key',
    'tenant_semantics' => 'tenant_scoped',
    'authorization_requirements' => ['sales.lead.write'],
    'deprecation_policy' => 'two-phase-migration',
    'compatibility_tests' => ['tests/unit/federation_cross_domain_contracts_v2.php'],
];
$contract = CrossDomainContract::fromArray($raw);
if ($contract->consumerDomain('growth') !== 'growth' || $contract->providerDomain('growth') !== 'sales') {
    throw new RuntimeException('Cross-domain ownership/roles regressed.');
}
if (!$contract->supportsVersion('2.7.0') || $contract->supportsVersion('3.0.0')) {
    throw new RuntimeException('Cross-domain version range is not enforced.');
}
if (CrossDomainContract::fromArray($contract->toArray())->toArray() !== $contract->toArray()) {
    throw new RuntimeException('Cross-domain v2 metadata round-trip failed.');
}
try {
    CrossDomainContract::fromArray(array_replace($raw, ['tenant_semantics' => 'client_supplied']));
    throw new RuntimeException('Expected invalid tenant semantics to be rejected.');
} catch (InvalidArgumentException $e) {
    if (!str_contains($e->getMessage(), 'tenant semantics')) throw $e;
}
$legacy = new CrossDomainContract(
    'Domains\\Sales\\Application\\Contract\\SalesWriteServiceFactoryInterface', 'requires', 'sales',
);
if ($legacy->version !== '1.0.0' || $legacy->supportedVersionRange !== '*') {
    throw new RuntimeException('Existing cross-domain constructor broke.');
}
echo "Federation CrossDomainContract V2 compatibility passed.\n";
