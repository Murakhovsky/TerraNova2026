<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmGovernanceRepositoryInterface
{
    public function monthlyBudget(string $organizationId, string $currency): ?float;

    public function monthlySpend(string $organizationId, string $currency): float;

    /**
     * Serialize budget-sensitive work for one organization/currency pair.
     * The implementation must keep the critical section exclusive until the callback returns.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function synchronizedBudget(string $organizationId, string $currency, callable $operation): mixed;

    public function record(LlmUsageRecord $usage): void;
}
