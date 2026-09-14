<?php
declare(strict_types=1);

namespace Domains\Property\Bootstrap;

use Domains\Property\Automation\Event\PropertyEventType;
use Domains\Property\Rule\PropertyRuleContextProvider;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\Contract\RuleProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;
use Kernel\Rule\Rule;

final readonly class PropertyDomainModule implements DomainModuleInterface, RuleProvidingModuleInterface, EventOwningModuleInterface
{
    private RuleContextProviderInterface $ruleContextProvider;

    public function __construct(?RuleContextProviderInterface $ruleContextProvider = null)
    {
        $this->ruleContextProvider = $ruleContextProvider ?? new PropertyRuleContextProvider();
    }

    public function name(): string
    {
        return 'property';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return PropertyEventType::values();
    }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return $this->ruleContextProvider;
    }

    /** @return list<Rule> */
    public function rules(string $organizationId): array
    {
        // Rules may now target canonical property.asset.* events, but defaults
        // remain empty until a concrete cross-domain automation is approved.
        return [];
    }
}
