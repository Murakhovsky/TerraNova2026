<?php
declare(strict_types=1);

namespace Platform\Audit\Contract;

use Kernel\Shared\Domain\OrganizationId;
use Platform\Audit\Model\ActivityHistoryEntry;
use Platform\Audit\Model\ResourceReference;

interface ActivityHistoryRepositoryInterface
{
    /** @return list<ActivityHistoryEntry> */
    public function recent(OrganizationId $organizationId, int $limit = 100): array;

    /** @return list<ActivityHistoryEntry> */
    public function recentForResource(OrganizationId $organizationId, ResourceReference $resource, int $limit = 100): array;

    /** @return list<ActivityHistoryEntry> */
    public function byCorrelationId(OrganizationId $organizationId, string $correlationId, int $limit = 100): array;
}
