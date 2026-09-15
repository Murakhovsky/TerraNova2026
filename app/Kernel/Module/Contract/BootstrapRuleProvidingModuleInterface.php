<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Rule\Rule;

/**
 * Exposes tenant-neutral bootstrap rule definitions owned by a Domain module.
 *
 * Runtime/effective rules remain available through RuleProvidingModuleInterface
 * and may vary by organization. Bootstrap rules are stable defaults suitable for
 * provisioning, documentation and architecture topology.
 */
interface BootstrapRuleProvidingModuleInterface
{
    /** @return list<Rule> */
    public function bootstrapRules(): array;
}
