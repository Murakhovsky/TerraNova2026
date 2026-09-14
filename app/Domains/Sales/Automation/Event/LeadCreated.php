<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class LeadCreated
{
    public const TYPE = 'sales.lead.created';

    public static function create(string $id, string $organizationId, string $leadId, array $payload, EventMetadata $metadata): DomainEvent
    {
        return new DomainEvent($id, $organizationId, self::TYPE, 'lead', $leadId, $payload, $metadata, new DateTimeImmutable());
    }
}
