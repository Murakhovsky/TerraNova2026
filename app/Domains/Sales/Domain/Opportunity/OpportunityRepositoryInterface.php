<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Opportunity;

use Kernel\Shared\Domain\OrganizationId;

interface OpportunityRepositoryInterface
{
    public function find(OrganizationId $organizationId, OpportunityId $opportunityId): ?Opportunity;
    public function save(Opportunity $opportunity): void;
}
