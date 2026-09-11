<?php
declare(strict_types=1);

namespace Kernel\Llm;

final readonly class StructuredLlmResponse
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
