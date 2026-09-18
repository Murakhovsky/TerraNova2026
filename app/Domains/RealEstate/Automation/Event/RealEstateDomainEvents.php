<?php
declare(strict_types=1);

namespace Domains\RealEstate\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class RealEstateDomainEvents
{
    /** @param array<string,mixed> $payload */
    public static function create(
        string $type,
        string $organizationId,
        string $caseId,
        array $payload,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            bin2hex(random_bytes(16)),
            $organizationId,
            $type,
            'brokerage_case',
            $caseId,
            $payload,
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
