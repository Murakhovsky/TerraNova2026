<?php
declare(strict_types=1);

namespace App\Application\Integration\Command;

use Kernel\Application\Command\CommandInterface;

final readonly class ReceiveCrmWebhookCommand implements CommandInterface
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public int $integrationId,
        public string $externalEventId,
        public string $eventType,
        public array $payload,
        public string $signature,
        public string $rawPayload,
        public string $correlationId,
    ) {
    }
}
