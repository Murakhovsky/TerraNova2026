<?php
declare(strict_types=1);

namespace Domains\Property\Application\Contract;

use Domains\Property\Application\DTO\PropertyIntelligenceContext;

interface PropertyIntelligenceProviderInterface
{
    /**
     * Return derived intelligence only. Canonical facts are immutable inputs and must not be rewritten.
     * @return array<string,mixed>
     */
    public function infer(PropertyIntelligenceContext $context): array;
}
