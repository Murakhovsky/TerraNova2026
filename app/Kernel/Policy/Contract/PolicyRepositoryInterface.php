<?php
declare(strict_types=1);

namespace Kernel\Policy\Contract;

use Kernel\Policy\ActionPolicy;

interface PolicyRepositoryInterface
{
    /** @return list<ActionPolicy> */
    public function activeFor(string $organizationId, string $actionType): array;
}
