<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Support;

use Kernel\Event\EventMetadata;

final class ClientCaseEvents
{
    public static function id(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function metadata(?array $user, ?string $correlationId = null): EventMetadata
    {
        return new EventMetadata(
            $correlationId ?? self::id(),
            null,
            isset($user['id']) ? 'USER' : 'SYSTEM',
            isset($user['id']) ? (string) $user['id'] : 'system',
        );
    }
}
