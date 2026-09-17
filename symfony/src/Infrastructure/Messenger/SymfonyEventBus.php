<?php
declare(strict_types=1);

namespace App\Infrastructure\Messenger;

use Kernel\Application\Bus\EventBusInterface;
use Kernel\Application\Event\EventInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SymfonyEventBus implements EventBusInterface
{
    public function __construct(private MessageBusInterface $messageBus)
    {
    }

    public function publish(EventInterface $event): void
    {
        $this->messageBus->dispatch($event);
    }
}
