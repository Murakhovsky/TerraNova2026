<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Domains\Diagnostic\Methodology\Result\DiagnosticResult;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticSessionEvaluated
{
    public const TYPE = 'diagnostic.session.evaluated';

    public static function create(
        string $eventId,
        string $organizationId,
        string $sessionId,
        DiagnosticResult $result,
        EventMetadata $metadata,
        DateTimeImmutable $occurredAt,
    ): DomainEvent {
        return new DomainEvent(
            $eventId,
            $organizationId,
            self::TYPE,
            'diagnostic_session',
            $sessionId,
            [
                'pack_id' => $result->packId,
                'pack_version' => $result->packVersion,
                'score' => $result->score,
                'coverage' => $result->coverage->ratio,
                'confidence' => $result->confidence,
                'finding_count' => count($result->findings),
            ],
            $metadata,
            $occurredAt,
        );
    }
}
