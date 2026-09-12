<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

interface DurableEventConsumerInterface extends EventHandlerInterface
{
    /** Stable consumer identity used for durable consumption/idempotency tracking. */
    public function consumerName(): string;
}
