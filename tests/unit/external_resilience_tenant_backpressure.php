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

function assertV0117(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

final class TestMetrics implements MetricsRecorderInterface
{
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void {}
}

final class TestCircuitGovernance implements LlmGovernanceRepositoryInterface, CircuitBreakerStoreInterface
{
    /** @var array<string,int> */ private array $failures = [];
    /** @var array<string,bool> */ private array $open = [];
    public int $records = 0;

    public function monthlyBudget(string $organizationId, string $currency): ?float { return null; }
    public function monthlySpend(string $organizationId, string $currency): float { return 0.0; }
    public function reserveBudget(string $organizationId, float $maxCostAmount, string $currency): LlmBudgetReservation { throw new LogicException('Not used.'); }
    public function settleBudget(LlmBudgetReservation $reservation, LlmUsageRecord $usage): void { throw new LogicException('Not used.'); }
    public function releaseBudget(LlmBudgetReservation $reservation): void {}
    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed { return $operation(); }
    public function record(LlmUsageRecord $usage): void { $this->records++; }

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

    private function key(?string $organizationId, string $serviceKey): string
    {
        return ($organizationId ?? '_global') . '|' . $serviceKey;
    }
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

$governance = new TestCircuitGovernance();
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
);
$request = new StructuredLlmRequest(
    systemPrompt: 'System',
    userPrompt: 'Question',
    context: [],
    responseSchema: ['type' => 'object'],
    organizationId: 'org-1',
    useCase: 'test.resilience',
);

$client->complete($request);
$client->complete($request);
assertV0117($primary->calls === 1, 'Open shared provider circuit must skip the degraded primary route on later calls.');
assertV0117($fallback->calls === 2, 'Fallback route must remain available while the primary circuit is open.');
assertV0117($governance->records === 2, 'Successful fallback calls must still record governed usage.');

$queueSource = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Queue/MysqlJobQueue.php');
foreach ([
    'cos_tenant_execution_leases',
    'GET_LOCK(:lock_name',
    'activeTenantLeaseCount',
    'deferUnclaimedRow',
    "attempts = attempts + 1",
] as $needle) {
    assertV0117(str_contains($queueSource, $needle), 'Tenant backpressure invariant missing: ' . $needle);
}
$deferPosition = strpos($queueSource, 'deferUnclaimedRow');
$attemptPosition = strpos($queueSource, 'attempts = attempts + 1');
assertV0117($deferPosition !== false && $attemptPosition !== false && $deferPosition < $attemptPosition,
    'Tenant throttling must happen before a job attempt is consumed.');

$eventStoreSource = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Event/MysqlEventStore.php');
assertV0117(str_contains($eventStoreSource, 'if (!$this->connection->inTransaction())'),
    'Durable event append must require the business transaction.');
assertV0117(str_contains($eventStoreSource, 'INSERT INTO cos_event_outbox'),
    'Durable event append must persist an outbox record in the same transaction.');

$outboxSource = (string) file_get_contents($root . '/app/Infrastructure/Platform/Persistence/MySql/Event/MysqlEventOutbox.php');
foreach (['FOR UPDATE SKIP LOCKED', 'recoverTimedOut', "status = 'DEAD'", 'public function replay'] as $needle) {
    assertV0117(str_contains($outboxSource, $needle), 'Durable outbox invariant missing: ' . $needle);
}

$governanceSource = (string) file_get_contents($root . '/app/Infrastructure/Llm/MysqlLlmGovernanceRepository.php');
assertV0117(str_contains($governanceSource, 'CircuitBreakerStoreInterface'),
    'External circuit state must be persisted outside the worker process.');
assertV0117(str_contains($governanceSource, 'cos_external_circuits'),
    'Persistent external circuit table is not wired into LLM governance.');

echo "COS Kernel V0.11.7 external resilience, tenant backpressure and durable outbox invariants passed.\n";
