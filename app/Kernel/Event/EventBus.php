<?php
declare(strict_types=1);

namespace Kernel\Event;

use InvalidArgumentException;
use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\Contract\EventStoreInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;
use Throwable;

final class EventBus
{
    /** @var array<string, list<EventHandlerInterface>> */
    private array $subscribers = [];

    public function __construct(
        private readonly EventStoreInterface $store,
        private readonly TransactionManagerInterface $transactions,
    ) {
    }

    public function subscribe(string $eventType, EventHandlerInterface $handler): void
    {
        if ($eventType === '') {
            throw new InvalidArgumentException('Event type must not be empty.');
        }

        $this->subscribers[$eventType][] = $handler;
    }

    public function publish(DomainEvent $event): void
    {
        if (!$this->transactions->isActive()) {
            $this->transactions->transactional(fn () => $this->publish($event));
            return;
        }

        $this->store->append($event);
        $this->transactions->afterCommit(fn () => $this->dispatch($event));
    }

    private function dispatch(DomainEvent $event): void
    {
        $handlers = array_merge($this->subscribers[$event->type] ?? [], $this->subscribers['*'] ?? []);
        foreach ($handlers as $handler) {
            try {
                $handler->handle($event);
            } catch (Throwable $exception) {
                // The business transaction is already committed; the outbox remains available for retry.
                error_log(sprintf('Event handler failed for %s (%s): %s', $event->type, $event->id, $exception->getMessage()));
            }
        }
    }
}
