<?php
declare(strict_types=1);

namespace Kernel\Agent;

final readonly class LlmResponse
{
    public function __construct(
        public array $output,
        public string $provider,
        public string $model,
        public ?int $inputTokens = null,
        public ?int $outputTokens = null,
        public ?float $costAmount = null,
        public ?string $costCurrency = null,
    ) {}
}
