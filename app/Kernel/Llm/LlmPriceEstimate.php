<?php
declare(strict_types=1);

namespace Kernel\Llm;

final readonly class LlmPriceEstimate
{
    public function __construct(
        public float $amount,
        public string $currency,
        public string $source = 'CALCULATED_SETTINGS',
        public ?string $pricingVersion = null,
    ) {}
}
