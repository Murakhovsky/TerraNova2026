<?php
declare(strict_types=1);

namespace Kernel\Resilience;

use Kernel\Execution\ExecutionFailureClassifier;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Observability\StructuredLoggerInterface;
use Kernel\Operations\Contract\MetricsRecorderInterface;
use Kernel\Resilience\Contract\CircuitBreakerStoreInterface;
use Throwable;

final readonly class ExternalCallExecutor
{
    public function __construct(
        private CircuitBreakerStoreInterface $circuits,
        private ?MetricsRecorderInterface $metrics = null,
        private ?StructuredLoggerInterface $logger = null,
    ) {}

    public function execute(
        ?string $organizationId,
        string $serviceKey,
        callable $operation,
        ?ExternalCallPolicy $policy = null,
    ): mixed {
        $policy ??= new ExternalCallPolicy();
        $serviceKey = trim($serviceKey);
        if ($serviceKey === '') {
            throw new \InvalidArgumentException('External service key cannot be empty.');
        }

        for ($attempt = 1; $attempt <= $policy->maxAttempts; $attempt++) {
            // Circuit admission is intentionally outside the operation catch block. An open
            // circuit is backpressure, not another failed provider attempt to count again.
            $this->circuits->assertAvailable($organizationId, $serviceKey);
            $started = hrtime(true);

            try {
                $result = $operation();
                $this->circuits->recordSuccess($organizationId, $serviceKey);
                $this->observe('cos.external.success', $organizationId, $serviceKey, $attempt, $started);
                return $result;
            } catch (Throwable $error) {
                $kind = ExecutionFailureClassifier::classify($error);
                if (!$kind->retryable()) {
                    // A permanent rejection still proves that the provider is reachable.
                    $this->circuits->recordSuccess($organizationId, $serviceKey);
                    $this->observe('cos.external.failure', $organizationId, $serviceKey, $attempt, $started, $error, $kind);
                    throw $error;
                }

                $this->circuits->recordRetryableFailure(
                    $organizationId,
                    $serviceKey,
                    $policy->failureThreshold,
                    $policy->circuitOpenSeconds,
                );
                $this->observe('cos.external.failure', $organizationId, $serviceKey, $attempt, $started, $error, $kind);

                if ($attempt >= $policy->maxAttempts) {
                    throw $error;
                }

                $delayMs = $policy->delayMilliseconds($attempt);
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        throw new \LogicException('External call executor exhausted without a result or exception.');
    }

    private function observe(
        string $legacyMetric,
        ?string $organizationId,
        string $serviceKey,
        int $attempt,
        int $started,
        ?Throwable $error = null,
        ?ExecutionFailureKind $failureKind = null,
    ): void {
        try {
            $outcome = $error === null ? 'success' : 'failure';
            $durationMs = max(0.0, (hrtime(true) - $started) / 1_000_000);
            $legacyLabels = [
                'service' => $serviceKey,
                'attempt' => (string) $attempt,
            ];
            if ($failureKind !== null) {
                $legacyLabels['retryable'] = $failureKind->retryable() ? '1' : '0';
            }

            // Preserve pre-V0.11.8 external metrics for compatibility.
            $this->metrics?->record($legacyMetric, $durationMs, $organizationId, $legacyLabels);

            $labels = [
                'runtime' => 'external',
                'service' => $serviceKey,
                'outcome' => $outcome,
            ];
            $this->metrics?->record('cos.execution.external_call_ms', $durationMs, $organizationId, $labels);
            $this->metrics?->record('cos.execution.throughput', 1, $organizationId, $labels);
            if ($attempt > 1) {
                $this->metrics?->record('cos.execution.retry_count', 1, $organizationId, [
                    'runtime' => 'external',
                    'service' => $serviceKey,
                ]);
            }
            if ($failureKind !== null) {
                $this->metrics?->record('cos.execution.failures', 1, $organizationId, [
                    ...$labels,
                    'failure_kind' => $failureKind->value,
                    'retryable' => $failureKind->retryable() ? '1' : '0',
                ]);
            }

            if ($error !== null) {
                $this->logger?->log('warning', 'External call failed.', [
                    'organization_id' => $organizationId,
                    'service' => $serviceKey,
                    'attempt' => $attempt,
                    'retryable' => $failureKind?->retryable(),
                    'failure_kind' => $failureKind?->value,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (Throwable) {
            // Telemetry must never alter provider or circuit state.
        }
    }
}
