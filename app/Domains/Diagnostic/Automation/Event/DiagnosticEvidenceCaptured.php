<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use Domains\Diagnostic\Model\Evidence;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticEvidenceCaptured
{
    public const TYPE = 'diagnostic.evidence.captured';

    public static function create(
        string $eventId,
        string $organizationId,
        string $sessionId,
        Evidence $evidence,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $eventId,
            $organizationId,
            self::TYPE,
            'diagnostic_session',
            $sessionId,
            ['evidence_id' => $evidence->id, 'evidence_type' => $evidence->type->value],
            $metadata,
            $evidence->capturedAt,
        );
    }
}
