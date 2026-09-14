<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

use Kernel\Event\DomainEvent;

interface EventStoreInterface
{
    public function append(DomainEvent $event): void;

    public function find(string $eventId): ?DomainEvent;

    /** @return list<DomainEvent> */
    public function findByAggregate(
        string $organizationId,
        string $aggregateType,
        string $aggregateId,
        int $limit = 100,
    ): array;
}
