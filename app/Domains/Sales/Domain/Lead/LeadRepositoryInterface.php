<?php
declare(strict_types=1);

namespace Domains\Sales\Domain\Lead;

use Kernel\Shared\Domain\OrganizationId;

interface LeadRepositoryInterface
{
    public function find(OrganizationId $organizationId, LeadId $leadId): ?Lead;
    public function save(Lead $lead): void;
}
