<?php
declare(strict_types=1);

namespace Platform\Notification\Model;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class Delivery
{
    /** @param array<string,mixed> $metadata */
    public function __construct(
        public string $id,
        public string $notificationId,
        public Channel $channel,
        public string $recipient,
        public DeliveryStatus $status,
        public int $attempts,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $deliveredAt = null,
        public ?string $providerMessageId = null,
        public ?string $error = null,
        public array $metadata = [],
    ) {
        if (trim($this->id) === '' || trim($this->notificationId) === '' || trim($this->recipient) === '' || $this->attempts < 0) {
            throw new InvalidArgumentException('Notification delivery identity/attempts are invalid.');
        }
        if ($this->status === DeliveryStatus::FAILED && ($this->error === null || trim($this->error) === '')) {
            throw new InvalidArgumentException('Failed notification delivery requires an error.');
        }
    }
}
