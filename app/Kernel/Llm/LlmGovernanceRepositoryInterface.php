<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmGovernanceRepositoryInterface
{
    public function monthlyBudget(string $organizationId, string $currency): ?float;

    public function monthlySpend(string $organizationId, string $currency): float;

    public function record(LlmUsageRecord $usage): void;
}
