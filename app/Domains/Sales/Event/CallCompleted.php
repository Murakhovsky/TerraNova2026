<?php
declare(strict_types=1);

namespace Domains\Sales\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class CallCompleted
{
    public const TYPE = 'sales.call.completed';

    public static function create(
        string $id,
        string $organizationId,
        string $dealId,
        int $duration,
        string $result,
        EventMetadata $metadata,
        ?string $transcriptReference = null,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'deal',
            $dealId,
            array_filter([
                'duration' => $duration,
                'result' => $result,
                'transcript_reference' => $transcriptReference,
            ], static fn (mixed $value): bool => $value !== null),
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
