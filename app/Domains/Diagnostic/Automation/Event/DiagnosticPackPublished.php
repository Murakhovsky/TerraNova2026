<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Domains\Diagnostic\Model\DiagnosticPack;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticPackPublished
{
    public const TYPE = 'diagnostic.pack.published';

    public static function create(
        string $eventId,
        string $organizationId,
        DiagnosticPack $pack,
        EventMetadata $metadata,
        DateTimeImmutable $occurredAt,
    ): DomainEvent {
        return new DomainEvent(
            $eventId,
            $organizationId,
            self::TYPE,
            'diagnostic_pack',
            $pack->id() . ':' . $pack->version(),
            ['pack_id' => $pack->id(), 'version' => $pack->version(), 'target_domain' => $pack->targetDomain()],
            $metadata,
            $occurredAt,
        );
    }
}
