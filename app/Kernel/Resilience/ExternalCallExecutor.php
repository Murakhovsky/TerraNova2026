<?php
declare(strict_types=1);

namespace Kernel\Resilience;

use Kernel\Execution\ExecutionFailureClassifier;
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
                    $this->observe('cos.external.failure', $organizationId, $serviceKey, $attempt, $started, $error, false);
                    throw $error;
                }

                $this->circuits->recordRetryableFailure(
                    $organizationId,
                    $serviceKey,
                    $policy->failureThreshold,
                    $policy->circuitOpenSeconds,
                );
                $this->observe('cos.external.failure', $organizationId, $serviceKey, $attempt, $started, $error, true);

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
        string $metric,
        ?string $organizationId,
        string $serviceKey,
        int $attempt,
        int $started,
        ?Throwable $error = null,
        ?bool $retryable = null,
    ): void {
        try {
            $labels = [
                'service' => $serviceKey,
                'attempt' => (string) $attempt,
            ];
            if ($retryable !== null) {
                $labels['retryable'] = $retryable ? '1' : '0';
            }
            $this->metrics?->record(
                $metric,
                (float) round((hrtime(true) - $started) / 1_000_000),
                $organizationId,
                $labels,
            );
            if ($error !== null) {
                $this->logger?->log('warning', 'External call failed.', [
                    'organization_id' => $organizationId,
                    'service' => $serviceKey,
                    'attempt' => $attempt,
                    'retryable' => $retryable,
                    'exception' => $error::class,
                    'error' => $error->getMessage(),
                ]);
            }
        } catch (Throwable) {
            // Telemetry must never alter provider or circuit state.
        }
    }
}
