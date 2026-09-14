<?php
declare(strict_types=1);

namespace Domains\Content\Application\Contract;

interface ContentIntegrationOutboxInterface
{
    public function stats(string $integration): array;
    public function enqueue(string $integration, string $eventType, string $entityType, int $entityId, array $payload): void;
}
