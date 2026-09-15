<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Policy\ActionPolicy;

/**
 * Exposes tenant-neutral bootstrap action policies owned by a Domain module.
 *
 * Runtime/effective policies remain available through PolicyProvidingModuleInterface
 * and may vary by organization. Bootstrap policies are stable defaults suitable for
 * provisioning, documentation and architecture topology.
 */
interface BootstrapPolicyProvidingModuleInterface
{
    /** @return list<ActionPolicy> */
    public function bootstrapPolicies(): array;
}
