<?php
declare(strict_types=1);

namespace Kernel\Llm;

use InvalidArgumentException;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Resilience\Contract\CircuitBreakerStoreInterface;
use Throwable;

final readonly class GovernedStructuredLlmClient implements StructuredLlmClientInterface
{
    private ?CircuitBreakerStoreInterface $circuits;

    public function __construct(
        private LlmProviderRegistry $providers,
        private LlmRoutingPolicy $routing,
        private LlmGovernanceRepositoryInterface $governance,
        private MetricsRecorderInterface $metrics,
        private string $budgetCurrency = 'USD',
        private int $circuitFailureThreshold = 5,
        private int $circuitOpenSeconds = 60,
    ) {
        $this->circuits = $governance instanceof CircuitBreakerStoreInterface ? $governance : null;
    }

    public function complete(StructuredLlmRequest $request): StructuredLlmResponse
    {
        if ($request->organizationId === null
            || $this->governance->monthlyBudget($request->organizationId, $this->budgetCurrency) === null) {
            return $this->completeGoverned($request);
        }

        if ($request->maxCostAmount === null) {
            throw new InvalidArgumentException('Budgeted structured LLM requests require maxCostAmount.');
        }

        try {
            $reservation = $this->governance->reserveBudget(
                $request->organizationId,
                $request->maxCostAmount,
                $this->budgetCurrency,
            );
        } catch (LlmBudgetExceededException $error) {
            $this->metrics->record('llm.budget.denied', 1.0, $request->organizationId, [
                'currency' => $this->budgetCurrency,
                'use_case' => $request->useCase ?? 'unspecified',
            ]);
            throw $error;
        }

        try {
            return $this->completeGoverned($request, $reservation);
        } catch (Throwable $error) {
            $this->governance->releaseBudget($reservation);
            throw $error;
        }
    }

    private function completeGoverned(
        StructuredLlmRequest $request,
        ?LlmBudgetReservation $reservation = null,
    ): StructuredLlmResponse {
        $routes = $this->routing->routesFor($request);
        $correlationId = $request->correlationId ?? bin2hex(random_bytes(16));
        $started = hrtime(true);
        $fallbackCount = 0;
        $lastRetryable = null;

        foreach ($routes as $index => $route) {
            $routedRequest = $request->routedTo($route->model);
            $serviceKey = 'llm.' . strtolower($route->provider);
            try {
                $this->circuits?->assertAvailable($request->organizationId, $serviceKey);
                $response = $this->providers->client($route->provider)->complete($routedRequest);
                $this->recordCircuitSuccess($request->organizationId, $serviceKey);
                $latencyMs = $this->duration($started);
                $this->recordSuccess($request, $response, $correlationId, $latencyMs, $fallbackCount, $reservation);
                return $response;
            } catch (LlmProviderException $error) {
                if ($error->retryable) {
                    $this->recordCircuitFailure($request->organizationId, $serviceKey);
                } else {
                    $this->recordCircuitSuccess($request->organizationId, $serviceKey);
                }
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
            } catch (ExecutionFailureException $error) {
                $this->metrics->record('llm.request.circuit_open', 1.0, $request->organizationId, [
                    'provider' => $route->provider,
                    'use_case' => $request->useCase ?? 'unspecified',
                ]);
                if (!$error->failureKind()->retryable() || $index === count($routes) - 1) {
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

        throw $lastRetryable ?? new LlmProviderException('unknown', false, 'No LLM route was executed.');
    }

    private function recordCircuitSuccess(?string $organizationId, string $serviceKey): void
    {
        if ($this->circuits === null) return;
        $this->circuits->recordSuccess($organizationId, $serviceKey);
    }

    private function recordCircuitFailure(?string $organizationId, string $serviceKey): void
    {
        if ($this->circuits === null) return;
        $this->circuits->recordRetryableFailure(
            $organizationId,
            $serviceKey,
            max(1, $this->circuitFailureThreshold),
            max(1, $this->circuitOpenSeconds),
        );
    }

    private function recordSuccess(
        StructuredLlmRequest $request,
        StructuredLlmResponse $response,
        string $correlationId,
        int $latencyMs,
        int $fallbackCount,
        ?LlmBudgetReservation $reservation,
    ): void {
        $costCurrency = $response->costAmount !== null
            ? strtoupper($response->costCurrency ?? $this->budgetCurrency)
            : null;

        $usage = new LlmUsageRecord(
            bin2hex(random_bytes(16)),
            $request->organizationId,
            $correlationId,
            $request->useCase,
            $response->provider,
            $response->model,
            $response->inputTokens,
            $response->outputTokens,
            $response->costAmount,
            $costCurrency,
            $latencyMs,
            $fallbackCount,
        );

        if ($reservation !== null) {
            $this->governance->settleBudget($reservation, $usage);
        } else {
            $this->governance->record($usage);
        }

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
                'currency' => $costCurrency ?? $this->budgetCurrency,
            ]);
        }
    }

    private function duration(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
