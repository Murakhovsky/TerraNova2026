<?php
declare(strict_types=1);

namespace Domains\RealEstate\Application\Contract;

interface SalesOpportunityReferenceInterface
{
    public function exists(string $organizationId, int $opportunityId): bool;
}
