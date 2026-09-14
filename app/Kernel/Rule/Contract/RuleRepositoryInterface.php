<?php
declare(strict_types=1);

namespace Kernel\Rule\Contract;

use Kernel\Rule\Rule;

interface RuleRepositoryInterface
{
    /** @return list<Rule> */
    public function activeFor(string $organizationId, string $trigger): array;
}
