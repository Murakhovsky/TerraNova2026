<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ClientCaseCreated
{
    public const TYPE = 'sales.client_case.created';

    public static function create(string $id, string $organizationId, string $clientCaseId, array $payload, EventMetadata $metadata): DomainEvent
    {
        return new DomainEvent($id, $organizationId, self::TYPE, 'client_case', $clientCaseId, $payload, $metadata, new DateTimeImmutable());
    }
}
