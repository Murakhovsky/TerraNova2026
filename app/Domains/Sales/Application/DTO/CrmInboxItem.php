<?php
declare(strict_types=1);

namespace Domains\Sales\Application\DTO;

final readonly class CrmInboxItem
{
    public function __construct(
        public string $id,
        public string $organizationId,
        public string $provider,
        public string $externalEventId,
        public string $eventType,
        public array $payload,
        public int $attempts,
        public string $correlationId,
    ) {
    }
}
