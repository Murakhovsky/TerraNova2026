<?php
declare(strict_types=1);

namespace Kernel\Tenant\Contract;

use Kernel\Tenant\Model\TenantContext;

interface TenantContextProviderInterface
{
    public function current(): ?TenantContext;
}
