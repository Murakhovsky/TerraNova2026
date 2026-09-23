<?php
declare(strict_types=1);

namespace Domains\Growth\Bootstrap;

use Domains\Growth\Automation\Action\GrowthSendMessageHandler;
use Domains\Growth\Automation\Event\GrowthEventType;
use Domains\Growth\Automation\Policy\GrowthPolicyCatalog;
use Kernel\Module\Contract\ActionOwningModuleInterface;
use Kernel\Module\Contract\BootstrapPolicyProvidingModuleInterface;
use Kernel\Module\Contract\EventOwningModuleInterface;
use Kernel\Module\Contract\PolicyProvidingModuleInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Policy\ActionPolicy;

final readonly class GrowthDomainModule implements
    DomainModuleInterface,
    EventOwningModuleInterface,
    ActionOwningModuleInterface,
    PolicyProvidingModuleInterface,
    BootstrapPolicyProvidingModuleInterface
{
    public function __construct(private GrowthSendMessageHandler $sendMessage) {}

    public function name(): string
    {
        return 'growth';
    }

    /** @return list<string> */
    public function eventTypes(): array
    {
        return GrowthEventType::values();
    }

    public function actionTypes(): array
    {
        return [GrowthSendMessageHandler::TYPE];
    }

    public function actionHandlers(): array
    {
        return [$this->sendMessage];
    }

    /** @return list<ActionPolicy> */
    public function policies(string $organizationId): array
    {
        return (new GrowthPolicyCatalog())->policies($organizationId);
    }

    /** @return list<ActionPolicy> */
    public function bootstrapPolicies(): array
    {
        return (new GrowthPolicyCatalog())->policies('default');
    }
}
