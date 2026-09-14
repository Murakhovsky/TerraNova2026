<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DiagnosticSessionCancelled
{
    public const TYPE = 'diagnostic.session.cancelled';

    public static function create(string $id, string $organizationId, string $sessionId, EventMetadata $metadata, DateTimeImmutable $at): DomainEvent
    {
        return new DomainEvent($id, $organizationId, self::TYPE, 'diagnostic_session', $sessionId, [], $metadata, $at);
    }
}
