<?php
declare(strict_types=1);

namespace Kernel\Llm;

interface LlmModelCatalogInterface
{
    /** @return array<string,array<string,mixed>> keyed by provider.model */
    public function models(): array;

    /** @return array<string,array<string,mixed>> keyed by provider.model */
    public function pricingCatalog(): array;

    public function version(): string;
}
