<?php
declare(strict_types=1);

namespace Domains\Service\Bootstrap;

use Domains\Service\Automation\Event\ServiceEventType;
use Domains\Service\Rule\ServiceRuleContextProvider;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class ServiceDomainModule implements DomainModuleInterface, EventOwningModuleInterface
{
    private RuleContextProviderInterface $ruleContextProvider;

    public function __construct(?RuleContextProviderInterface $ruleContextProvider = null)
    {
        $this->ruleContextProvider = $ruleContextProvider ?? new ServiceRuleContextProvider();
    }

    public function name(): string { return 'service'; }

    /** @return list<string> */
    public function eventTypes(): array { return ServiceEventType::values(); }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return $this->ruleContextProvider;
    }
}
