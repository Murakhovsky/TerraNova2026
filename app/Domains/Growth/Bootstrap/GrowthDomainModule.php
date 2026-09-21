<?php
declare(strict_types=1);

namespace Domains\Growth\Bootstrap;

use Kernel\Module\DomainModuleInterface;

final readonly class GrowthDomainModule implements DomainModuleInterface
{
    public function name(): string
    {
        return 'growth';
    }
}
