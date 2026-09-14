<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use Domains\Diagnostic\Model\DiagnosticRecord;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticRecordAdded
{
    public const TYPE = 'diagnostic.record.added';

    public static function create(
        string $eventId,
        string $organizationId,
        string $sessionId,
        DiagnosticRecord $record,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $eventId,
            $organizationId,
            self::TYPE,
            'diagnostic_session',
            $sessionId,
            [
                'record_id' => $record->id,
                'record_type' => $record->type->value,
                'criterion_code' => $record->criterionCode,
                'evidence_ids' => $record->evidenceIds,
                'upstream_record_ids' => $record->upstreamRecordIds,
            ],
            $metadata,
            $record->recordedAt,
        );
    }
}
