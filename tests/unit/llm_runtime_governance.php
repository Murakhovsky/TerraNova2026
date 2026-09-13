<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Llm\GovernedStructuredLlmClient;
use Kernel\Llm\LlmBudgetExceededException;
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
use Kernel\Operations\Contract\MetricsRecorderInterface;

$repository = new class implements LlmGovernanceRepositoryInterface {
    public ?float $budget = null;
    public float $spent = 0.0;
    public int $criticalSections = 0;
    public int $reserveCalls = 0;
    public int $settleCalls = 0;
    public int $releaseCalls = 0;
    public bool $insideBudgetSection = false;
    public array $reservations = [];
    public array $usage = [];

    public function monthlyBudget(string $organizationId, string $currency): ?float { return $this->budget; }
    public function monthlySpend(string $organizationId, string $currency): float { return $this->spent; }
    public function reserveBudget(string $organizationId, float $maxCostAmount, string $currency): LlmBudgetReservation
    {
        $this->reserveCalls++;
        return $this->synchronizedBudget($organizationId, $currency, function () use ($organizationId, $maxCostAmount, $currency): LlmBudgetReservation {
            $reserved = array_sum(array_map(static fn (LlmBudgetReservation $item): float => $item->maxCostAmount, $this->reservations));
            if ($this->budget !== null && $this->spent + $reserved + $maxCostAmount > $this->budget) {
                throw new LlmBudgetExceededException($organizationId, $this->spent + $reserved, $this->budget, $currency);
            }
            $reservation = new LlmBudgetReservation(bin2hex(random_bytes(8)), $organizationId, $maxCostAmount, strtoupper($currency));
            $this->reservations[$reservation->id] = $reservation;
            return $reservation;
        });
    }
    public function settleBudget(LlmBudgetReservation $reservation, LlmUsageRecord $usage): void
    {
        $this->settleCalls++;
        $this->synchronizedBudget($reservation->organizationId, $reservation->currency, function () use ($reservation, $usage): void {
            if (!isset($this->reservations[$reservation->id])) throw new RuntimeException('Reservation is not active.');
            $this->record($usage);
            unset($this->reservations[$reservation->id]);
        });
    }
    public function releaseBudget(LlmBudgetReservation $reservation): void
    {
        $this->releaseCalls++;
        $this->synchronizedBudget($reservation->organizationId, $reservation->currency, function () use ($reservation): void {
            unset($this->reservations[$reservation->id]);
        });
    }
    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed
    {
        $this->criticalSections++;
        if ($this->insideBudgetSection) throw new RuntimeException('Budget section re-entered unexpectedly.');
        $this->insideBudgetSection = true;
        try { return $operation(); } finally { $this->insideBudgetSection = false; }
    }
    public function record(LlmUsageRecord $usage): void
    {
        $this->usage[] = $usage;
        $this->spent += $usage->costAmount ?? 0.0;
    }
};
$metrics = new class implements MetricsRecorderInterface {
    public array $records = [];
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void
    { $this->records[] = [$metric, $value, $organizationId, $labels]; }
};

$primary = new class($repository) implements StructuredLlmClientInterface {
    public int $calls = 0;
    public bool $retryable = true;
    public function __construct(private object $repository) {}
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        if ($this->repository->insideBudgetSection) throw new RuntimeException('Provider call ran under the budget lock.');
        $this->calls++;
        throw new LlmProviderException('primary', $this->retryable, 'simulated provider failure', $this->retryable ? 503 : 400);
    }
};
$fallback = new class($repository) implements StructuredLlmClientInterface {
    public int $calls = 0;
    public ?StructuredLlmRequest $lastRequest = null;
    public function __construct(private object $repository) {}
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        if ($this->repository->insideBudgetSection) throw new RuntimeException('Provider call ran under the budget lock.');
        $this->calls++;
        $this->lastRequest = $request;
        return new StructuredLlmResponse(['ok' => true], 'fallback', (string) $request->model, 10, 5, 0.02, null);
    }
};

$client = new GovernedStructuredLlmClient(new LlmProviderRegistry(['primary' => $primary, 'fallback' => $fallback]), new LlmRoutingPolicy([new LlmRoute('primary', 'primary-model'), new LlmRoute('fallback', 'fallback-model')]), $repository, $metrics);
$request = new StructuredLlmRequest(systemPrompt: 'system', userPrompt: 'question', context: [], responseSchema: ['type' => 'object'], organizationId: 'org-1', useCase: 'diagnostic.test', correlationId: 'corr-1');
$response = $client->complete($request);
if ($response->provider !== 'fallback' || $primary->calls !== 1 || $fallback->calls !== 1) throw new RuntimeException('Fallback routing failed.');
if (count($repository->usage) !== 1 || $repository->usage[0]->fallbackCount !== 1 || $repository->usage[0]->correlationId !== 'corr-1') throw new RuntimeException('Usage metadata was not recorded.');
if ($repository->usage[0]->costCurrency !== 'USD') throw new RuntimeException('Default cost currency was not applied.');
if ($repository->reserveCalls !== 0 || $repository->criticalSections !== 0) throw new RuntimeException('Unbudgeted organizations must remain fully concurrent.');

$repository->budget = 1.0;
$repository->spent = 0.0;
$budgetedRequest = new StructuredLlmRequest(systemPrompt: 'system', userPrompt: 'question', context: [], responseSchema: ['type' => 'object'], maxCostAmount: 0.25, organizationId: 'org-1', useCase: 'diagnostic.test', correlationId: 'corr-budgeted');
$client->complete($budgetedRequest);
if ($repository->reserveCalls !== 1 || $repository->settleCalls !== 1 || $repository->reservations !== []) throw new RuntimeException('Budget reservation was not settled.');
if ($fallback->lastRequest?->maxCostAmount !== 0.25) throw new RuntimeException('Routing lost the maximum cost bound.');

$held = $repository->reserveBudget('org-1', 0.74, 'USD');
$providerCalls = $primary->calls + $fallback->calls;
try { $client->complete($budgetedRequest); throw new RuntimeException('Expected reservation-aware budget denial.'); } catch (LlmBudgetExceededException) {}
if ($primary->calls + $fallback->calls !== $providerCalls) throw new RuntimeException('Denied request reached a provider.');
$repository->releaseBudget($held);

$primary->retryable = false;
$releaseBefore = $repository->releaseCalls;
try { $client->complete($budgetedRequest); throw new RuntimeException('Expected non-retryable provider exception.'); } catch (LlmProviderException $error) { if ($error->retryable) throw $error; }
if ($repository->releaseCalls !== $releaseBefore + 1 || $repository->reservations !== []) throw new RuntimeException('Failed governed call leaked its reservation.');

$providerCalls = $primary->calls + $fallback->calls;
try { $client->complete($request); throw new RuntimeException('Expected missing maxCostAmount rejection.'); } catch (InvalidArgumentException) {}
if ($primary->calls + $fallback->calls !== $providerCalls) throw new RuntimeException('Missing maxCostAmount reached a provider.');

$metricNames = array_column($metrics->records, 0);
foreach (['llm.request.latency_ms', 'llm.request.fallback_count', 'llm.request.input_tokens', 'llm.request.output_tokens', 'llm.request.cost', 'llm.request.error', 'llm.budget.denied'] as $metric) {
    if (!in_array($metric, $metricNames, true)) throw new RuntimeException('Missing LLM governance metric: ' . $metric);
}

echo "LLM runtime governance invariants passed.\n";
