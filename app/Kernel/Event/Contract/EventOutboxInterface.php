<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

use Kernel\Event\OutboxMessage;
use Throwable;

interface EventOutboxInterface
{
    public function claim(string $workerId): ?OutboxMessage;

    public function markPublished(OutboxMessage $message): void;

    public function markFailed(OutboxMessage $message, Throwable $error): void;

    public function recoverTimedOut(int $leaseSeconds = 300): int;

    public function replay(?string $organizationId = null, ?string $eventId = null): int;
}
