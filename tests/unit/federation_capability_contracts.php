<?php
declare(strict_types=1);

use Kernel\Module\CapabilityContract;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Module\ModuleCatalog;
use Kernel\Module\ModuleContributions;
use Kernel\Module\ModuleDefinition;
use Kernel\Module\ModuleManifest;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$reject = static function (callable $run, string $part): void {
    try {
        $run();
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), $part)) return;
        throw $e;
    }
    throw new RuntimeException('Expected validation failure containing: ' . $part);
};

$declared = [
    'id' => 'sales.lead.qualify',
    'version' => '1.0.0',
    'owner_domain' => 'sales',
    'kind' => 'command',
    'input_schema' => 'SalesLeadQualifyInputV1',
    'output_schema' => 'SalesLeadQualifyResultV1',
    'execution_binding' => 'salesLeadQualificationHandler',
    'side_effect_level' => 'internal',
    'permission' => 'sales.lead.qualify',
    'idempotency' => 'required',
    'timeout_policy' => ['seconds' => 30],
    'retry_policy' => ['max_attempts' => 2],
    'failure_contract' => 'SalesLeadFailureV1',
    'tests' => ['tests/unit/federation_capability_contracts.php'],
];
$parsed = CapabilityContract::fromArray($declared);
$assert($parsed->toArray()['version'] === '1.0.0', 'Typed capability did not round-trip.');

$contributions = ModuleContributions::fromArray([
    'capabilities' => ['sales.lead.qualify', 'sales.workspace'],
    'capability_contracts' => [$declared],
]);
$catalog = new CanonicalCapabilityCatalog(new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('sales', 'Sales', '1.0.0'), $contributions),
]));
$assert($catalog->ownerOf('sales.lead.qualify') === 'sales', 'Manifest ownership missing.');
$assert($catalog->describe('sales.workspace') === null, 'Legacy descriptive capability became executable.');
$withoutEvidence = $catalog->driftReport();
$assert($withoutEvidence['sales.lead.qualify']['status'] === 'INVALID', 'Unverified binding must not be called consistent.');
$assert($withoutEvidence['sales.workspace']['status'] === 'DECLARATIVE_ONLY', 'Legacy descriptive capability is not classified.');
$withEvidence = $catalog->driftReport(['salesLeadQualificationHandler' => 'sales.lead.qualify', 'orphanBinding' => 'unknown.mutation']);
$assert($withEvidence['sales.lead.qualify']['status'] === 'CONSISTENT', 'Verified binding was not reconciled.');
$assert($withEvidence['unknown.mutation']['status'] === 'EXECUTABLE_ONLY', 'Orphan binding not classified.');

$reject(static fn () => CapabilityContract::fromArray(array_replace($declared, ['permission' => null])), 'permission');
$reject(static fn () => CapabilityContract::fromArray(array_replace($declared, ['idempotency' => 'none'])), 'idempotency');
$reject(static fn () => CapabilityContract::fromArray(array_replace($declared, ['version' => 'beta'])), 'version');
$reject(static fn () => CapabilityContract::fromArray(array_replace($declared, [
    'side_effect_level' => 'external', 'approval_policy' => 'none',
])), 'approval policy');
$reject(static fn () => new CanonicalCapabilityCatalog(new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('sales', 'Sales', '1.0.0'), ModuleContributions::fromArray([
        'capabilities' => ['sales.lead.qualify'],
        'capability_contracts' => [array_replace($declared, ['owner_domain' => 'growth'])],
    ])),
])), 'differs from manifest');
$reject(static fn () => new CanonicalCapabilityCatalog(new ModuleCatalog([
    new ModuleDefinition(new ModuleManifest('sales', 'Sales', '1.0.0'), ModuleContributions::fromArray([
        'capabilities' => [], 'capability_contracts' => [$declared],
    ])),
])), 'matching identity');

echo "Federation canonical capability contracts passed.\n";
