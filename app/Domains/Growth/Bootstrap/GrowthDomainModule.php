<?php
declare(strict_types=1);

namespace Domains\Growth\Bootstrap;

use Domains\Growth\Automation\Action\GrowthCallHandler;
use Domains\Growth\Automation\Action\GrowthLinkedInHandler;
use Domains\Growth\Automation\Action\GrowthSendMessageHandler;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Automation\Policy\GrowthPolicyCatalog;
use Domains\Growth\Rule\GrowthRuleContextProvider;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Policy\ActionPolicy;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class GrowthDomainModule implements
    DomainModuleInterface,
    EventOwningModuleInterface,
    ActionOwningModuleInterface,
    PolicyProvidingModuleInterface,
    BootstrapPolicyProvidingModuleInterface
{
    public function __construct(
        private ?GrowthSendMessageHandler $sendMessage=null,
        private ?GrowthLinkedInHandler $linkedIn=null,
        private ?GrowthCallHandler $call=null,
    ) {}

    public function name():string{return 'growth';}

    /** @return list<string> */
    public function eventTypes():array{return GrowthEventType::values();}

    public function ruleContextProvider():RuleContextProviderInterface{return new GrowthRuleContextProvider();}

    public function actionTypes():array
    {
        return [GrowthSendMessageHandler::TYPE,GrowthLinkedInHandler::TYPE,GrowthCallHandler::TYPE];
    }

    public function actionHandlers():array
    {
        return array_values(array_filter(
            [$this->sendMessage,$this->linkedIn,$this->call],
            static fn(object|null $handler):bool=>$handler!==null,
        ));
    }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId):array
    {
        return (new GrowthPolicyCatalog())->policies($organizationId);
    }

    /** @return list<ActionPolicy> */
    public function bootstrapPolicies():array
    {
        return (new GrowthPolicyCatalog())->policies('default');
    }
}
