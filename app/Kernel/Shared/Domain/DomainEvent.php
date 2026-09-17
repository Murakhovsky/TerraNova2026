<?php
declare(strict_types=1);

namespace Kernel\Shared\Domain;

use DateTimeImmutable;

interface DomainEvent
{
    public function eventId(): string;

    public function eventName(): string;

    public function occurredAt(): DateTimeImmutable;
}
