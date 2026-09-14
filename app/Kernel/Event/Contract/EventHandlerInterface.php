<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

use Kernel\Event\DomainEvent;

interface EventHandlerInterface
{
    public function handle(DomainEvent $event): void;
}
