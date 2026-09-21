<?php
declare(strict_types=1);

namespace Domains\Growth\Bootstrap;

use Domains\Growth\Automation\Event\GrowthEventType;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class GrowthDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    public function name(): string
    {
        return 'growth';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return GrowthEventType::values();
    }
}
