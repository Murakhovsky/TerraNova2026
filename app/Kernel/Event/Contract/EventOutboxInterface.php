<?php
declare(strict_types=1);

namespace Kernel\Event\Contract;

use Kernel\Event\DomainEvent;

interface EventOutboxInterface
{
    public function append(DomainEvent $event): void;
}
