<?php
declare(strict_types=1);

namespace Domains\Sales\Application\Contract;

use Domains\Sales\Application\DTO\CrmInboxItem;
use Throwable;

interface CrmInboxRepositoryInterface
{
    public function receive(
        string $organizationId,
        string $provider,
        string $externalEventId,
        string $eventType,
        array $payload,
        string $correlationId,
    ): string;

    public function claim(string $organizationId, string $id, string $workerId): ?CrmInboxItem;

    public function complete(CrmInboxItem $item): void;

    public function fail(CrmInboxItem $item, Throwable $error): void;
}
