<?php
declare(strict_types=1);

namespace Domains\Sales\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class LeadChanged
{
    public const TYPE = 'sales.lead.changed';

    public static function create(
        string $id,
        string $organizationId,
        string $leadId,
        array $changes,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'lead',
            $leadId,
            ['changes' => $changes],
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
