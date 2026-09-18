<?php
declare(strict_types=1);

namespace Domains\RealEstate\Bootstrap;

use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;

final readonly class RealEstateDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    public function name(): string { return 'real_estate'; }

    /** @return list<string> */
    public function eventTypes(): array { return RealEstateEventType::values(); }
}
