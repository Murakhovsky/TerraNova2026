<?php
declare(strict_types=1);

namespace Domains\Property\Bootstrap;

use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Rule\Rule;

final readonly class PropertyDomainModule implements DomainModuleInterface, RuleProvidingModuleInterface
{
    public function name(): string
    {
        return 'property';
    }

    /** @return list<Rule> */
    public function rules(string $organizationId): array
    {
        // V0.2.1 establishes a tenant-scoped configuration namespace.
        // Property-owned runtime rules are introduced only when the domain
        // commands/events they govern exist, rather than inventing defaults now.
        return [];
    }
}
