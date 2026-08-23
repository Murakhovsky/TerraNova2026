<?php
declare(strict_types=1);

namespace Kernel\Event;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class DomainEvent
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $type,
        public string $aggregateType,
        public string $aggregateId,
        public array $payload,
        public EventMetadata $metadata,
        public DateTimeImmutable $occurredAt,
    ) {
        foreach (['id' => $id, 'organizationId' => $organizationId, 'type' => $type] as $field => $value) {
            if ($value === '') {
                throw new InvalidArgumentException(sprintf('%s must not be empty.', $field));
            }
        }
    }
}
