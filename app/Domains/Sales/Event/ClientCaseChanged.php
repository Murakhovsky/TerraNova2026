<?php
declare(strict_types=1);

namespace Domains\Sales\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ClientCaseChanged
{
    public const TYPE = 'sales.client_case.changed';

    public static function create(
        string $id,
        string $organizationId,
        string $clientCaseId,
        array $changes,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'client_case',
            $clientCaseId,
            ['changes' => $changes],
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
