<?php
declare(strict_types=1);

namespace Domains\RealEstate\Infrastructure\Sales;

use Domains\RealEstate\Application\Contract\SalesOpportunityReferenceInterface;
use Domains\Sales\Application\Contract\SalesWorkspaceReadModelInterface;

final readonly class SalesOpportunityReferenceAdapter implements SalesOpportunityReferenceInterface
{
    public function __construct(private SalesWorkspaceReadModelInterface $sales) {}

    public function exists(string $organizationId, int $opportunityId): bool
    {
        return $opportunityId > 0 && $this->sales->deal($organizationId, $opportunityId) !== null;
    }
}
