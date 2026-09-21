<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Process\JsonProcessRegistry;
use Kernel\Process\ProcessDefinition;
use Kernel\Process\ProcessEdge;
use Kernel\Process\ProcessStep;
use Kernel\Process\RuntimeMapping;

$registry = new JsonProcessRegistry($root . '/resources/processes');
if (count($registry->all()) !== 7) throw new RuntimeException('Canonical Process Registry must expose seven current processes.');

$property = $registry->get('property.submission-to-publication');
if (!$property instanceof ProcessDefinition || $property->schemaVersion !== 4 || count($property->steps) !== 6) {
    throw new RuntimeException('Property process was not hydrated into schema-v4 Kernel process model.');
}
if ($property->steps[0]->capability !== 'property.intake' || $property->steps[0]->domain !== 'property') {
    throw new RuntimeException('Process capability bridge was not preserved by Kernel hydration.');
}

$sales = $registry->get('sales.lead-to-managed-case');
if (!$sales instanceof ProcessDefinition || $sales->steps[0]->capability !== null || $sales->steps[0]->capabilityGap !== 'missing-domain-capability') {
    throw new RuntimeException('Explicit capability gap was not preserved by Kernel hydration.');
}

$crossDomain = $registry->get('sales.request-to-property-match');
if (!$crossDomain instanceof ProcessDefinition || $crossDomain->schemaVersion !== 5) {
    throw new RuntimeException('Schema-v5 cross-domain process was not hydrated.');
}
$resolveProperty = null;
foreach ($crossDomain->steps as $step) {
    if ($step->id === 'resolve-property') $resolveProperty = $step;
}
if (!$resolveProperty instanceof ProcessStep) throw new RuntimeException('Cross-domain Property step is missing.');
if ($resolveProperty->domain !== 'property' || $resolveProperty->capability !== 'property.reference') {
    throw new RuntimeException('Cross-domain Property capability ownership was not preserved.');
}
$contractMappings = array_values(array_filter(
    $resolveProperty->runtime,
    static fn (RuntimeMapping $mapping): bool => $mapping->type === 'contract',
));
if (count($contractMappings) !== 1 || $contractMappings[0]->ref !== 'Domains\\Property\\Contract\\PropertyReferencePort') {
    throw new RuntimeException('Cross-domain Property step lost PropertyReferencePort mapping.');
}

$brokerage = $registry->get('real_estate.opportunity-to-reservation');
if (!$brokerage instanceof ProcessDefinition || $brokerage->schemaVersion !== 5 || $brokerage->domain !== 'real_estate') {
    throw new RuntimeException('RealEstate brokerage process was not hydrated.');
}
if (count($brokerage->steps) !== 7 || $brokerage->steps[0]->domain !== 'sales') {
    throw new RuntimeException('RealEstate brokerage process lost cross-domain topology.');
}

$service = $registry->get('service.request-to-close');
if (!$service instanceof ProcessDefinition || $service->schemaVersion !== 4 || $service->domain !== 'service' || count($service->steps) !== 7) {
    throw new RuntimeException('Service Request → Close process was not hydrated.');
}
if ($service->steps[0]->capability !== 'service.request' || $service->steps[6]->capability !== 'service.ticket') {
    throw new RuntimeException('Service process capability bridge was not preserved.');
}

$growth = $registry->get('growth.opportunity-candidate-to-handoff');
if (!$growth instanceof ProcessDefinition || $growth->schemaVersion !== 4 || $growth->domain !== 'growth' || count($growth->steps) !== 6) {
    throw new RuntimeException('Growth Opportunity Candidate → Handoff process was not hydrated.');
}
if ($growth->steps[0]->capability !== 'growth.signal.detect' || $growth->steps[5]->capability !== 'growth.handoff.prepare') {
    throw new RuntimeException('Growth process capability bridge was not preserved.');
}

if (!$registry->has('diagnostic.session-to-recommendation') || $registry->has('missing.process')) {
    throw new RuntimeException('Process Registry lookup contract failed.');
}

try {
    new RuntimeMapping('imaginary', 'x');
    throw new RuntimeException('RuntimeMapping accepted an unsupported mapping type.');
} catch (\InvalidArgumentException) {}

try {
    new ProcessStep('x', 'X', 'operation', 'owner', 'property', 'sales.workspace.use');
    throw new RuntimeException('ProcessStep accepted a capability outside its Domain namespace.');
} catch (\InvalidArgumentException) {}

try {
    new ProcessStep('x', 'X', 'operation', 'owner', 'sales', null, null);
    throw new RuntimeException('ProcessStep accepted an implicit capability gap.');
} catch (\InvalidArgumentException) {}

$baseStep = new ProcessStep('a', 'A', 'operation', 'owner', 'sales', null, 'missing-domain-capability');
$foreignStepWithoutContract = new ProcessStep('b', 'B', 'operation', 'owner', 'property', 'property.reference');
try {
    new ProcessDefinition(
        schemaVersion: 5,
        id: 'sales.invalid-cross-domain',
        title: 'Invalid cross-domain',
        domain: 'sales',
        state: 'as-is',
        workflow: '02-workflows/invalid.md',
        trigger: 'test',
        outcomes: ['done'],
        actors: ['owner'],
        steps: [$baseStep, $foreignStepWithoutContract],
        edges: [new ProcessEdge('a', 'b')],
    );
    throw new RuntimeException('Schema-v5 cross-domain process accepted a foreign step without contract mapping.');
} catch (\InvalidArgumentException) {}

$foreignStepWithContract = new ProcessStep(
    'b',
    'B',
    'operation',
    'owner',
    'property',
    'property.reference',
    null,
    true,
    [new RuntimeMapping('contract', 'Domains\\Property\\Contract\\PropertyReferencePort')],
);
$new = new ProcessDefinition(
    schemaVersion: 5,
    id: 'sales.valid-cross-domain',
    title: 'Valid cross-domain',
    domain: 'sales',
    state: 'as-is',
    workflow: '02-workflows/valid.md',
    trigger: 'test',
    outcomes: ['done'],
    actors: ['owner'],
    steps: [$baseStep, $foreignStepWithContract],
    edges: [new ProcessEdge('a', 'b')],
);
if ($new->steps[1]->domain !== 'property') throw new RuntimeException('Valid schema-v5 cross-domain process was not preserved.');

echo "Process V0.1 schema-v4/v5 registry invariants passed.\n";
