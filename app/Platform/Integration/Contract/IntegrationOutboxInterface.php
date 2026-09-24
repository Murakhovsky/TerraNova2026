<?php
declare(strict_types=1);

namespace Platform\Integration\Contract;

interface IntegrationOutboxInterface
{
    /**
     * @param array<string,mixed> $payload
     */
    public function enqueue(
        string $integration,
        string $eventType,
        string $entityType,
        ?int $entityId,
        array $payload,
        string $dedupeKey,
    ):string;
}
