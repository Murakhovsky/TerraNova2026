<?php
declare(strict_types=1);

namespace Kernel\Event\Service;

use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\DomainEvent;

final class EventDispatcher
{
    /** @var array<string, list<EventHandlerInterface>> */
    private array $handlers = [];

    public function register(string $eventType, EventHandlerInterface $handler): void
    {
        $this->handlers[$eventType][] = $handler;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->handlers[$event->type] ?? [] as $handler) {
            $handler->handle($event);
        }
    }
}
