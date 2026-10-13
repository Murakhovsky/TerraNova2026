<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root.'/symfony/src/Engineering/Domain/Workflow/EngineeringId.php';
require $root.'/symfony/src/Engineering/Domain/Agent/AgentRole.php';
require $root.'/symfony/src/Engineering/Domain/Agent/EngineeringAgentTask.php';

use App\Engineering\Domain\Agent\AgentRole;
use App\Engineering\Domain\Agent\EngineeringAgentTask;
use App\Engineering\Domain\Workflow\EngineeringId;

$featureId = EngineeringId::generate();
$revision = str_repeat('a', 40);
$specHash = str_repeat('b', 64);
$previousHash = str_repeat('c', 64);
$rawKey = $featureId.':architect:12:'.$revision.':'.$specHash.':'.$previousHash;
if (strlen($rawKey) <= EngineeringAgentTask::MAX_IDEMPOTENCY_KEY_LENGTH) {
    throw new RuntimeException('Fixture must reproduce the Architect VARCHAR(190) failure.');
}

$make = static fn (string $key): EngineeringAgentTask => new EngineeringAgentTask(
    id: EngineeringId::generate(),
    featureId: $featureId,
    role: AgentRole::PRINCIPAL_ARCHITECT,
    objective: 'Review architecture',
    inputs: [],
    contextRefs: [],
    constraints: [],
    expectedOutputSchema: 'architect-result',
    completionCriteria: [],
    idempotencyKey: $key,
    inputSnapshot: [],
);

$first = $make($rawKey);
$second = $make($rawKey);
if ($first->idempotencyKey !== $second->idempotencyKey
    || strlen($first->idempotencyKey) > EngineeringAgentTask::MAX_IDEMPOTENCY_KEY_LENGTH) {
    throw new RuntimeException('Architect rerun key must be deterministic and fit VARCHAR(190).');
}
if (!str_starts_with($first->idempotencyKey, 'eng:sha256:v1:')) {
    throw new RuntimeException('Long key must have an explicit digest namespace.');
}
if ($first->idempotencyKey === $make($rawKey.':different-attempt')->idempotencyKey
    || $first->idempotencyKey === $make(str_replace($specHash, str_repeat('d', 64), $rawKey))->idempotencyKey
    || $first->idempotencyKey === $make($rawKey.':evidence:1:'.str_repeat('e', 64))->idempotencyKey) {
    throw new RuntimeException('Distinct AgentRun attempts and evidence contexts must have distinct keys.');
}

$short = $featureId.':qa-plan:1:'.$specHash;
if (strlen($short) > EngineeringAgentTask::MAX_IDEMPOTENCY_KEY_LENGTH
    || $make($short)->idempotencyKey !== $short) {
    throw new RuntimeException('Existing short idempotency keys must remain byte-for-byte compatible.');
}
$edge = str_repeat('x', EngineeringAgentTask::MAX_IDEMPOTENCY_KEY_LENGTH);
if ($make($edge)->idempotencyKey !== $edge || strlen($make($edge.'y')->idempotencyKey) > 190) {
    throw new RuntimeException('Idempotency key length boundary failed.');
}
if (EngineeringAgentTask::canonicalIdempotencyKey($rawKey) !== $first->idempotencyKey) {
    throw new RuntimeException('Persistence lookup normalization diverged from task normalization.');
}
foreach (['', '   '] as $empty) {
    try {
        $make($empty);
        throw new RuntimeException('Empty idempotency keys must be rejected.');
    } catch (InvalidArgumentException) {}
}

$store = (string) file_get_contents($root.'/symfony/src/Engineering/Infrastructure/Persistence/Doctrine/DoctrineEngineeringAgentRunStore.php');
if (!str_contains($store, 'EngineeringAgentTask::canonicalIdempotencyKey($key)')) {
    throw new RuntimeException('AgentRun repository lookups must normalize the same composite key.');
}

echo "Engineering AgentRun idempotency key length and uniqueness passed.\n";
