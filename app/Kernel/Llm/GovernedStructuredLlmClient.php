<?php
declare(strict_types=1);

namespace Kernel\Llm;

use Kernel\Operations\Contract\MetricsRecorderInterface;
use Throwable;

final readonly class GovernedStructuredLlmClient implements StructuredLlmClientInterface
{
    public function __construct(
        private LlmProviderRegistry $providers,
        private LlmRoutingPolicy $routing,
        private LlmGovernanceRepositoryInterface $governance,
        private MetricsRecorderInterface $metrics,
        private string $budgetCurrency = 'USD',
    ) {
    }

    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        $this->assertBudget($request);

        $routes = $this->routing->routesFor($request);
        $correlationId = $request->correlationId ?? bin2hex(random_bytes(16));
        $started = hrtime(true);
        $fallbackCount = 0;
        $lastRetryable = null;

        foreach ($routes as $index => $route) {
            $routedRequest = $request->routedTo($route->model);
            try {
                $response = $this->providers->client($route->provider)->complete($routedRequest);
                $latencyMs = $this->duration($started);
                $this->recordSuccess($request, $response, $correlationId, $latencyMs, $fallbackCount);
                return $response;
            } catch (LlmProviderException $error) {
                $this->metrics->record('llm.request.error', 1.0, $request->organizationId, [
                    'provider' => $route->provider,
                    'use_case' => $request->useCase ?? 'unspecified',
                    'retryable' => $error->retryable ? 'true' : 'false',
                ]);
                if (!$error->retryable || $index === count($routes) - 1) {
                    throw $error;
                }
                $lastRetryable = $error;
                $fallbackCount++;
            } catch (Throwable $error) {
                $this->metrics->record('llm.request.error', 1.0, $request->organizationId, [
                    'provider' => $route->provider,
                    'use_case' => $request->useCase ?? 'unspecified',
                    'retryable' => 'false',
                ]);
                throw $error;
            }
        }

        // Defensive only: routing policies cannot be empty.
        throw $lastRetryable ?? new LlmProviderException('unknown', false, 'No LLM route was executed.');
    }

    private function assertBudget(StructuredLlmRequest $request): void
    {
        if ($request->organizationId === null) {
            return;
        }

        $limit = $this->governance->monthlyBudget($request->organizationId, $this->budgetCurrency);
        if ($limit === null) {
            return;
        }

        $spent = $this->governance->monthlySpend($request->organizationId, $this->budgetCurrency);
        if ($spent >= $limit) {
            $this->metrics->record('llm.budget.denied', 1.0, $request->organizationId, [
                'currency' => $this->budgetCurrency,
                'use_case' => $request->useCase ?? 'unspecified',
            ]);
            throw new LlmBudgetExceededException($request->organizationId, $spent, $limit, $this->budgetCurrency);
        }
    }

    private function recordSuccess(
        StructuredLlmRequest $request,
        StructuredLlmResponse $response,
        string $correlationId,
        int $latencyMs,
        int $fallbackCount,
    ): void {
        $this->governance->record(new LlmUsageRecord(
            bin2hex(random_bytes(16)),
            $request->organizationId,
            $correlationId,
            $request->useCase,
            $response->provider,
            $response->model,
            $response->inputTokens,
            $response->outputTokens,
            $response->costAmount,
            $response->costCurrency,
            $latencyMs,
            $fallbackCount,
        ));

        $labels = [
            'provider' => $response->provider,
            'model' => $response->model,
            'use_case' => $request->useCase ?? 'unspecified',
        ];
        $this->metrics->record('llm.request.latency_ms', (float) $latencyMs, $request->organizationId, $labels);
        $this->metrics->record('llm.request.fallback_count', (float) $fallbackCount, $request->organizationId, $labels);
        if ($response->inputTokens !== null) {
            $this->metrics->record('llm.request.input_tokens', (float) $response->inputTokens, $request->organizationId, $labels);
        }
        if ($response->outputTokens !== null) {
            $this->metrics->record('llm.request.output_tokens', (float) $response->outputTokens, $request->organizationId, $labels);
        }
        if ($response->costAmount !== null) {
            $this->metrics->record('llm.request.cost', $response->costAmount, $request->organizationId, $labels + [
                'currency' => $response->costCurrency ?? $this->budgetCurrency,
            ]);
        }
    }

    private function duration(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
