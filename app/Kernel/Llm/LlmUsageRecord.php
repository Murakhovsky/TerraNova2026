<?php
declare(strict_types=1);

namespace Kernel\Llm;

final readonly class LlmUsageRecord
{
    public function __construct(
        public string $id,
        public ?string $organizationId,
        public string $correlationId,
        public ?string $useCase,
        public string $provider,
        public string $model,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public ?float $costAmount,
        public ?string $costCurrency,
        public int $latencyMs,
        public int $fallbackCount,
        public ?int $cachedInputTokens = null,
        public ?int $reasoningTokens = null,
        public ?string $costSource = null,
        public ?string $pricingVersion = null,
        public ?string $providerRequestId = null,
    ) {
    }
}
