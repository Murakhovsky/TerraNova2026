<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use Kernel\Action\Service\ActionHandlerRegistry;
use Kernel\Module\DomainModuleRegistry;

final readonly class SalesActionHandlerRegistryFactory
{
    public function create(DomainModuleRegistry $domains): ActionHandlerRegistry
    {
        return new ActionHandlerRegistry($domains->actionHandlerMap());
    }
}
