<?php
declare(strict_types=1);

namespace Kernel\Event;

use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class EventBus
{
    public function __construct(
        private EventStoreInterface $store,
        private TransactionManagerInterface $transactions,
    ) {
    }

    public function publish(DomainEvent $event): void
    {
        if (!$this->transactions->isActive()) {
            $this->transactions->transactional(fn () => $this->publish($event));
            return;
        }

        $this->store->append($event);
    }
}
