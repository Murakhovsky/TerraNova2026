<?php
declare(strict_types=1);

namespace Kernel\Event;

final readonly class OutboxMessage
{
    public function __construct(
        public int $id,
        public string $eventId,
        public string $organizationId,
        public int $attempts,
        public string $claimedBy,
    ) {
    }
}
