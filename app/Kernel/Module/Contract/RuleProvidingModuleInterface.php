<?php
declare(strict_types=1);

namespace Kernel\Module\Contract;

use Kernel\Rule\Rule;

interface RuleProvidingModuleInterface
{
    /** @return list<Rule> */
    public function rules(string $organizationId): array;
}
