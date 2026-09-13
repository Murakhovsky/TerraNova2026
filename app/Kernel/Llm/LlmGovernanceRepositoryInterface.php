<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmGovernanceRepositoryInterface
{
    public function monthlyBudget(string $organizationId, string $currency): ?float;

    public function monthlySpend(string $organizationId, string $currency): float;

    public function reserveBudget(string $organizationId, float $maxCostAmount, string $currency): LlmBudgetReservation;

    public function settleBudget(LlmBudgetReservation $reservation, LlmUsageRecord $usage): void;

    public function releaseBudget(LlmBudgetReservation $reservation): void;

    /**
     * Backward-compatible low-level critical section. New governed execution must
     * never place an external provider call inside this lock.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed;

    public function record(LlmUsageRecord $usage): void;
}
