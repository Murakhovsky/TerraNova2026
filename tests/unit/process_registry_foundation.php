<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Infrastructure\Process\JsonProcessRegistry;
use InvalidArgumentException;
use Kernel\Process\ProcessDefinition;
use Kernel\Process\ProcessStep;
use Kernel\Process\RuntimeMapping;

$registry = new JsonProcessRegistry($root . '/resources/processes');
if (count($registry->all()) !== 3) throw new RuntimeException('Canonical Process Registry must expose three migrated processes.');

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
if (!$registry->has('diagnostic.session-to-recommendation') || $registry->has('missing.process')) {
    throw new RuntimeException('Process Registry lookup contract failed.');
}

try {
    new RuntimeMapping('imaginary', 'x');
    throw new RuntimeException('RuntimeMapping accepted an unsupported mapping type.');
} catch (InvalidArgumentException) {}

try {
    new ProcessStep('x', 'X', 'operation', 'owner', 'property', 'sales.workspace.use');
    throw new RuntimeException('ProcessStep accepted a capability outside its Domain namespace.');
} catch (InvalidArgumentException) {}

try {
    new ProcessStep('x', 'X', 'operation', 'owner', 'sales', null, null);
    throw new RuntimeException('ProcessStep accepted an implicit capability gap.');
} catch (InvalidArgumentException) {}

echo "Process V0.1 schema-v4 registry invariants passed.\n";
