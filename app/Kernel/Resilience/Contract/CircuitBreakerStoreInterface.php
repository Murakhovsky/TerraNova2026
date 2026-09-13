<?php
declare(strict_types=1);

namespace Kernel\Resilience\Contract;

interface CircuitBreakerStoreInterface
{
    /** Throws when the circuit is currently open. */
    public function assertAvailable(?string $organizationId, string $serviceKey): void;

    public function recordSuccess(?string $organizationId, string $serviceKey): void;

    public function recordRetryableFailure(
        ?string $organizationId,
        string $serviceKey,
        int $failureThreshold,
        int $openSeconds,
    ): void;
}
