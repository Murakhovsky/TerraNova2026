<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Domains\Diagnostic\Model\DiagnosticSession;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticSessionStarted
{
    public const TYPE = 'diagnostic.session.started';

    public static function create(
        string $eventId,
        string $organizationId,
        DiagnosticSession $session,
        EventMetadata $metadata,
        DateTimeImmutable $occurredAt,
    ): DomainEvent {
        return new DomainEvent(
            $eventId,
            $organizationId,
            self::TYPE,
            'diagnostic_session',
            $session->id(),
            [
                'pack_id' => $session->packId(),
                'pack_version' => $session->packVersion(),
                'target' => $session->target()->toArray(),
            ],
            $metadata,
            $occurredAt,
        );
    }
}
