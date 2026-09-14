<?php
declare(strict_types=1);

namespace Kernel\Configuration\Contract;

use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Rule;

interface ConfigurationStoreInterface
{
    /** @param list<Rule> $rules @param list<ActionPolicy> $policies */
    public function provision(
        string $organizationId,
        string $domainName,
        array $rules,
        array $policies,
        string $manifestHash,
        string $actorId,
    ): void;
}
