<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmPricingResolverInterface
{
    public function estimate(
        ?string $organizationId,
        string $provider,
        string $model,
        ?int $inputTokens,
        ?int $outputTokens,
        ?int $cachedInputTokens = null,
        ?int $reasoningTokens = null,
    ): ?LlmPriceEstimate;
}
