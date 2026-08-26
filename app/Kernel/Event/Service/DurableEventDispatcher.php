<?php
declare(strict_types=1);

namespace Kernel\Event\Service;

use InvalidArgumentException;
use Kernel\Event\Contract\EventConsumptionRepositoryInterface;
use Kernel\Event\Contract\EventHandlerInterface;
use Kernel\Event\DomainEvent;
use Throwable;

final class DurableEventDispatcher
{
    /** @var array<string, EventHandlerInterface> */
    private array $consumers = [];

    /** @param array<string, EventHandlerInterface> $consumers */
    public function __construct(
        private readonly EventConsumptionRepositoryInterface $consumptions,
        array $consumers = [],
    ) {
        foreach ($consumers as $name => $consumer) {
            $this->register($name, $consumer);
        }
    }

    public function register(string $name, EventHandlerInterface $consumer): void
    {
        if (!preg_match('/^[a-z][a-z0-9._-]{2,159}$/', $name)) {
            throw new InvalidArgumentException('Invalid durable event consumer name: ' . $name);
        }
        if (isset($this->consumers[$name])) {
            throw new InvalidArgumentException('Duplicate durable event consumer: ' . $name);
        }
        $this->consumers[$name] = $consumer;
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->consumers as $name => $consumer) {
            if (!$this->consumptions->begin($event, $name)) {
                continue;
            }
            try {
                $consumer->handle($event);
                $this->consumptions->complete($event, $name);
            } catch (Throwable $error) {
                $this->consumptions->fail($event, $name, $error);
                throw $error;
            }
        }
    }
}
