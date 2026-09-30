<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Execution\ExecutionFailureException;
use Kernel\Llm\GovernedStructuredLlmClient;
use Kernel\Llm\LlmBudgetReservation;
use Kernel\Llm\LlmGovernanceRepositoryInterface;
use Kernel\Llm\LlmProviderException;
use Kernel\Llm\LlmProviderRegistry;
use Kernel\Llm\LlmRoute;
use Kernel\Llm\LlmRoutingPolicy;
use Kernel\Llm\LlmUsageRecord;
use Kernel\Llm\StructuredLlmClientInterface;
use Kernel\Llm\StructuredLlmRequest;
use Kernel\Llm\StructuredLlmResponse;
use Kernel\Module\KernelVersion;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Resilience\Contract\CircuitBreakerStoreInterface;
use Kernel\Resilience\ExternalCallExecutor;
use Kernel\Resilience\ExternalCallPolicy;

function assertV0117(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class TestMetrics implements MetricsRecorderInterface
{
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void {}
}

final class MemoryCircuitStore implements CircuitBreakerStoreInterface
{
    /** @var array<string,int> */ private array $failures = [];
    /** @var array<string,bool> */ private array $open = [];

    public function assertAvailable(?string $organizationId, string $serviceKey): void
    {
        if ($this->open[$this->key($organizationId, $serviceKey)] ?? false) {
            throw ExecutionFailureException::externalUnavailable('circuit open');
        }
    }

    public function recordSuccess(?string $organizationId, string $serviceKey): void
    {
        $key = $this->key($organizationId, $serviceKey);
        $this->failures[$key] = 0;
        $this->open[$key] = false;
    }

    public function recordRetryableFailure(?string $organizationId, string $serviceKey, int $failureThreshold, int $openSeconds): void
    {
        $key = $this->key($organizationId, $serviceKey);
        $this->failures[$key] = ($this->failures[$key] ?? 0) + 1;
        if ($this->failures[$key] >= $failureThreshold) $this->open[$key] = true;
    }

    public function isOpen(?string $organizationId, string $serviceKey): bool
    {
        return $this->open[$this->key($organizationId, $serviceKey)] ?? false;
    }

    private function key(?string $organizationId, string $serviceKey): string
    {
        return ($organizationId ?? '_global') . '|' . $serviceKey;
    }
}

final class TestGovernance implements LlmGovernanceRepositoryInterface
{
    public int $records = 0;
    public function monthlyBudget(string $organizationId, string $currency): ?float { return null; }
    public function monthlySpend(string $organizationId, string $currency): float { return 0.0; }
    public function reserveBudget(string $organizationId, float $maxCostAmount, string $currency): LlmBudgetReservation { throw new LogicException('Not used.'); }
    public function settleBudget(LlmBudgetReservation $reservation, LlmUsageRecord $usage): void { throw new LogicException('Not used.'); }
    public function releaseBudget(LlmBudgetReservation $reservation): void {}
    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed { return $operation(); }
    public function record(LlmUsageRecord $usage): void { $this->records++; }
}

final class RetryableProvider implements StructuredLlmClientInterface
{
    public int $calls = 0;
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->calls++;
        throw new LlmProviderException('primary', true, 'upstream unavailable', 503);
    }
}

final class SuccessfulProvider implements StructuredLlmClientInterface
{
    public int $calls = 0;
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->calls++;
        return new StructuredLlmResponse(['ok' => true], 'fallback', $request->model ?? 'fallback-model');
    }
}

assertV0117(version_compare(KernelVersion::VERSION, '0.11.7', '>='), 'Kernel version must advertise V0.11.7+.');

// Generic resilience retries transient failures and resets the circuit on success.
$store = new MemoryCircuitStore();
$executor = new ExternalCallExecutor($store, new TestMetrics());
$calls = 0;
$result = $executor->execute('org-1', 'crm.test', function () use (&$calls): string {
    $calls++;
    if ($calls === 1) throw ExecutionFailureException::externalUnavailable('temporary');
    return 'ok';
}, new ExternalCallPolicy(2, 0, 0, 5, 60));
assertV0117($result === 'ok' && $calls === 2, 'Transient external failure must retry and recover.');
assertV0117(!$store->isOpen('org-1', 'crm.test'), 'Successful retry must close/reset the circuit.');

// Permanent failures are never retried and must not poison provider availability.
$permanentCalls = 0;
try {
    $executor->execute('org-1', 'crm.permanent', function () use (&$permanentCalls): never {
        $permanentCalls++;
        throw ExecutionFailureException::permanent('bad request');
    }, new ExternalCallPolicy(3, 0, 0, 1, 60));
    throw new RuntimeException('Permanent external failure unexpectedly succeeded.');
} catch (ExecutionFailureException $error) {
    assertV0117($error->failureKind()->retryable() === false, 'Permanent failure classification changed.');
}
assertV0117($permanentCalls === 1, 'Permanent external failure must not retry.');
assertV0117(!$store->isOpen('org-1', 'crm.permanent'), 'Permanent rejection must not open the circuit.');

// Production LLM routing uses the generic executor with a provider-global circuit. One tenant's
// exhausted provider outage protects subsequent traffic instead of each tenant relearning it.
$globalStore = new MemoryCircuitStore();
$globalExecutor = new ExternalCallExecutor($globalStore, new TestMetrics());
$governance = new TestGovernance();
$primary = new RetryableProvider();
$fallback = new SuccessfulProvider();
$client = new GovernedStructuredLlmClient(
    new LlmProviderRegistry(['primary' => $primary, 'fallback' => $fallback]),
    new LlmRoutingPolicy([
        new LlmRoute('primary', 'primary-model'),
        new LlmRoute('fallback', 'fallback-model'),
    ]),
    $governance,
    new TestMetrics(),
    'USD',
    1,
    60,
    $globalExecutor,
);
$requestOrg1 = new StructuredLlmRequest(
    systemPrompt: 'System', userPrompt: 'Question', context: [], responseSchema: ['type' => 'object'],
    organizationId: 'org-1', useCase: 'test.resilience',
);
$requestOrg2 = new StructuredLlmRequest(
    systemPrompt: 'System', userPrompt: 'Question', context: [], responseSchema: ['type' => 'object'],
    organizationId: 'org-2', useCase: 'test.resilience',
);
$client->complete($requestOrg1);
$client->complete($requestOrg2);
assertV0117($primary->calls === 1, 'Provider-global circuit must skip degraded primary across tenants.');
assertV0117($fallback->calls === 2, 'Fallback must remain available while primary circuit is open.');
assertV0117($governance->records === 2, 'Successful fallback calls must still record governed usage.');

$queueSource = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php');
foreach ([
    'cos_tenant_execution_leases',
    'GET_LOCK(:lock_name',
    'activeTenantLeaseCount',
    'claimSql()',
    'ORDER BY {$activeLeaseCount} ASC',
    "attempts = attempts + 1",
] as $needle) {
    assertV0117(str_contains($queueSource, $needle), 'Tenant backpressure invariant missing: ' . $needle);
}
assertV0117(!str_contains($queueSource, 'deferUnclaimedRow'),
    'Tenant backpressure must not mutate waiting jobs just to scan past a saturated tenant.');
$leaseCheck = strpos($queueSource, 'activeTenantLeaseCount');
$attemptIncrement = strpos($queueSource, 'attempts = attempts + 1');
assertV0117($leaseCheck !== false && $attemptIncrement !== false && $leaseCheck < $attemptIncrement,
    'Tenant admission must happen before a job attempt is consumed.');

$bootstrapSource = (string) file_get_contents($root . '/app/Bootstrap/InfrastructureServices.php');
foreach ([
    'COS_TENANT_JOB_CONCURRENCY',
    'cosExternalCircuitBreakerStore',
    'cosExternalCallExecutor',
    'MysqlCircuitBreakerStore',
] as $needle) {
    assertV0117(str_contains($bootstrapSource, $needle), 'Production resilience composition missing: ' . $needle);
}

$circuitSource = (string) file_get_contents(
    $root . '/app/Infrastructure/Platform/Persistence/MySql/Resilience/MysqlCircuitBreakerStore.php'
);
foreach (['HALF_OPEN_PROBE_SECONDS', 'opened_until <= UTC_TIMESTAMP(6)', 'rowCount() !== 1'] as $needle) {
    assertV0117(str_contains($circuitSource, $needle), 'Persistent half-open circuit invariant missing: ' . $needle);
}

echo "COS Kernel V0.11.7 resilience and tenant backpressure hardening passed.\n";
