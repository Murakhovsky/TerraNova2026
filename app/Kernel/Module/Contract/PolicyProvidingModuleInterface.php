<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Policy\ActionPolicy;

interface PolicyProvidingModuleInterface
{
    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array;
}
