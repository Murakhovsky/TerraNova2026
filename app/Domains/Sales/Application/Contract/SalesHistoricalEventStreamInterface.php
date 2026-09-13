<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Kernel\Event\DomainEvent;

interface SalesHistoricalEventStreamInterface
{
    /** @return iterable<DomainEvent> */
    public function forOrganization(string $organizationId): iterable;
}
