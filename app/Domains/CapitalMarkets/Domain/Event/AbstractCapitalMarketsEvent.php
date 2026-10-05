<?php
declare(strict_types=1);

namespace Domains\CapitalMarkets\Domain\Event;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\DomainEvent;

abstract readonly class AbstractCapitalMarketsEvent implements DomainEvent
{
    public function __construct(
        private string $id,
        private DateTimeImmutable $occurredAt,
    ) {
        if ($this->id === '' || trim($this->id) !== $this->id) {
            throw new InvalidArgumentException('Capital Markets event id must be a trimmed non-empty value.');
        }
    }

    final public function eventId(): string
    {
        return $this->id;
    }

    final public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
