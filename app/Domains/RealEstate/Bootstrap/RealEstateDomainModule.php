<?php
declare(strict_types=1);

namespace Domains\RealEstate\Bootstrap;

use Domains\RealEstate\Automation\Event\RealEstateEventType;
use Domains\RealEstate\Rule\RealEstateRuleContextProvider;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class RealEstateDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    private RuleContextProviderInterface $ruleContextProvider;

    public function __construct(?RuleContextProviderInterface $ruleContextProvider = null)
    {
        $this->ruleContextProvider = $ruleContextProvider ?? new RealEstateRuleContextProvider();
    }

    public function name(): string { return 'real_estate'; }

    /** @return list<string> */
    public function eventTypes(): array { return RealEstateEventType::values(); }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return $this->ruleContextProvider;
    }
}
