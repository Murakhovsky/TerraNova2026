<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

abstract class AggregateRoot extends Entity
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    final protected function recordDomainEvent(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /** @return list<DomainEvent> */
    final public function releaseDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    final public function hasRecordedDomainEvents(): bool
    {
        return $this->domainEvents !== [];
    }
}
