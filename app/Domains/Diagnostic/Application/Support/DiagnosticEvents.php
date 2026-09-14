<?php
declare(strict_types=1);

namespace Domains\Diagnostic\Application\Support;

use Kernel\Event\EventMetadata;

final class DiagnosticEvents
{
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function metadata(string $actorType, string $actorId, ?string $correlationId = null): EventMetadata
    {
        return new EventMetadata($correlationId ?? self::id(), null, $actorType, $actorId);
    }
}
