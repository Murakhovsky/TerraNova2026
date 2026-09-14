<?php
declare(strict_types=1);

namespace Kernel\Rule\Contract;

use Kernel\Event\DomainEvent;

interface RuleContextProviderInterface
{
    public function contextFor(DomainEvent $event): array;
}
