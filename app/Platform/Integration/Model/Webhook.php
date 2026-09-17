<?php
declare(strict_types=1);

namespace Platform\Integration\Model;

use DateTimeImmutable;
use InvalidArgumentException;
use Kernel\Shared\Domain\OrganizationId;

final readonly class Webhook
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $id,
        public OrganizationId $organizationId,
        public string $connectionId,
        public string $eventType,
        public array $payload,
        public bool $signatureVerified,
        public DateTimeImmutable $receivedAt,
        public ?string $externalEventId = null,
    ) {
        if (trim($this->id) === '' || trim($this->connectionId) === '' || trim($this->eventType) === '') {
            throw new InvalidArgumentException('Webhook requires id, connection and event type.');
        }
    }
}
