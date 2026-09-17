<?php
declare(strict_types=1);

namespace Kernel\Application\Bus;

use Kernel\Application\Event\EventInterface;

interface EventBusInterface
{
    public function publish(EventInterface $event): void;
}
