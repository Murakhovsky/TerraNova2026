<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Domains\Diagnostic\Model\DiagnosticSession;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticSessionCompleted
{
    public const TYPE = 'diagnostic.session.completed';

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
            ['record_count' => count($session->records()), 'evidence_count' => count($session->evidence())],
            $metadata,
            $occurredAt,
        );
    }
}
