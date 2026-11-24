<?php
declare(strict_types=1);
namespace App\Application\Capability\Contract;

use Kernel\Module\CapabilityContract;
use Kernel\Tenant\Model\TenantContext;

/** Optional domain-owned transaction authorization for direct commands. */
interface TransactionalCapabilityInvocationPolicyInterface extends CapabilityInvocationPolicyInterface
{
    public function execute(TenantContext $actor,CapabilityContract $contract,array $input,callable $operation):mixed;
}
