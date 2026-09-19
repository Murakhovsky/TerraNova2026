<?php
declare(strict_types=1);

namespace Domains\Service\Automation\Event;

final class ServiceEventType
{
    public const REQUEST_CREATED = 'service.request.created';
    public const TICKET_CREATED = 'service.ticket.created';
    public const TICKET_ASSIGNED = 'service.ticket.assigned';
    public const SLA_SET = 'service.sla.set';
    public const TICKET_ESCALATED = 'service.ticket.escalated';
    public const TICKET_RESOLVED = 'service.ticket.resolved';
    public const TICKET_CLOSED = 'service.ticket.closed';

    /** @return list<string> */
    public static function values(): array
    {
        return [
            self::REQUEST_CREATED,
            self::TICKET_CREATED,
            self::TICKET_ASSIGNED,
            self::SLA_SET,
            self::TICKET_ESCALATED,
            self::TICKET_RESOLVED,
            self::TICKET_CLOSED,
        ];
    }
}
