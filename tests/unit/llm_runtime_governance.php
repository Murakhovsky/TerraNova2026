<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';

use Kernel\Llm\GovernedStructuredLlmClient;
use Kernel\Llm\LlmBudgetExceededException;
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
    /** @var list<LlmUsageRecord> */
    public array $usage = [];

    public function monthlyBudget(string $organizationId, string $currency): ?float { return $this->budget; }
    public function monthlySpend(string $organizationId, string $currency): float { return $this->spent; }
    public function record(LlmUsageRecord $usage): void { $this->usage[] = $usage; }
};
$metrics = new class implements MetricsRecorderInterface {
    public array $records = [];
    public function record(string $metric, float $value, ?string $organizationId = null, array $labels = []): void
    { $this->records[] = [$metric, $value, $organizationId, $labels]; }
};

$primary = new class implements StructuredLlmClientInterface {
    public int $calls = 0;
    public bool $retryable = true;
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->calls++;
        throw new LlmProviderException('primary', $this->retryable, 'simulated provider failure', $this->retryable ? 503 : 400);
    }
};
$fallback = new class implements StructuredLlmClientInterface {
    public int $calls = 0;
    public ?StructuredLlmRequest $lastRequest = null;
    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->calls++;
        $this->lastRequest = $request;
        return new StructuredLlmResponse(['ok' => true], 'fallback', (string) $request->model, 10, 5, 0.02, 'USD');
    }
};

$client = new GovernedStructuredLlmClient(
    new LlmProviderRegistry(['primary' => $primary, 'fallback' => $fallback]),
    new LlmRoutingPolicy([
        new LlmRoute('primary', 'primary-model'),
        new LlmRoute('fallback', 'fallback-model'),
    ]),
    $repository,
    $metrics,
);

$request = new StructuredLlmRequest(
    systemPrompt: 'system',
    userPrompt: 'question',
    context: [],
    responseSchema: ['type' => 'object'],
    organizationId: 'org-1',
    useCase: 'diagnostic.test',
    correlationId: 'corr-1',
);
$response = $client->complete($request);
if ($response->provider !== 'fallback' || $response->model !== 'fallback-model') {
    throw new RuntimeException('Retryable provider failure did not route to the configured fallback.');
}
if ($primary->calls !== 1 || $fallback->calls !== 1) {
    throw new RuntimeException('Unexpected provider call counts during fallback.');
}
if (count($repository->usage) !== 1 || $repository->usage[0]->fallbackCount !== 1 || $repository->usage[0]->correlationId !== 'corr-1') {
    throw new RuntimeException('Governed LLM usage was not recorded with fallback/correlation metadata.');
}

// Explicit governance for a use case wins over a domain model hint.
$policyClient = new GovernedStructuredLlmClient(
    new LlmProviderRegistry(['fallback' => $fallback]),
    new LlmRoutingPolicy(
        [new LlmRoute('fallback', 'default-model')],
        ['diagnostic.deep' => [new LlmRoute('fallback', 'governed-model')]],
    ),
    $repository,
    $metrics,
);
$policyClient->complete(new StructuredLlmRequest(
    systemPrompt: 'system',
    userPrompt: 'question',
    context: [],
    responseSchema: ['type' => 'object'],
    model: 'domain-model',
    organizationId: 'org-1',
    useCase: 'diagnostic.deep',
));
if ($fallback->lastRequest?->model !== 'governed-model') {
    throw new RuntimeException('Use-case governance did not override the domain model hint.');
}

// Non-retryable provider failures must never be hidden by fallback.
$primary->retryable = false;
$fallbackCalls = $fallback->calls;
try {
    $client->complete($request);
    throw new RuntimeException('Expected non-retryable LLM provider exception.');
} catch (LlmProviderException $error) {
    if ($error->retryable) throw $error;
}
if ($fallback->calls !== $fallbackCalls) {
    throw new RuntimeException('Non-retryable LLM failure incorrectly invoked fallback.');
}

// Budget denial happens before any provider call and is never a fallback condition.
$repository->budget = 1.0;
$repository->spent = 1.0;
$primaryCalls = $primary->calls;
$fallbackCalls = $fallback->calls;
try {
    $client->complete($request);
    throw new RuntimeException('Expected LLM budget denial.');
} catch (LlmBudgetExceededException) {
}
if ($primary->calls !== $primaryCalls || $fallback->calls !== $fallbackCalls) {
    throw new RuntimeException('Budget denial must happen before provider execution.');
}

$metricNames = array_column($metrics->records, 0);
foreach (['llm.request.latency_ms', 'llm.request.fallback_count', 'llm.request.input_tokens', 'llm.request.output_tokens', 'llm.request.cost', 'llm.request.error', 'llm.budget.denied'] as $metric) {
    if (!in_array($metric, $metricNames, true)) {
        throw new RuntimeException('Missing LLM governance metric: ' . $metric);
    }
}

echo "LLM runtime governance invariants passed.\n";
