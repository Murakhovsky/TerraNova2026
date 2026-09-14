<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

use Kernel\Event\DomainEvent;
use Throwable;

interface EventConsumptionRepositoryInterface
{
    /** Returns false when this consumer has already completed the event. */
    public function begin(DomainEvent $event, string $consumerName): bool;

    public function complete(DomainEvent $event, string $consumerName): void;

    public function fail(DomainEvent $event, string $consumerName, Throwable $error): void;

    public function reset(?string $organizationId = null, ?string $eventId = null): int;
}
