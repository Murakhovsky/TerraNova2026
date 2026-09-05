<?php
declare(strict_types=1);

namespace Domains\Sales\Bootstrap;

use Domains\Sales\Application\Contract\CrmGatewayInterface;
use Domains\Sales\Application\Contract\DealRepositoryInterface;
use Domains\Sales\Application\Contract\FollowupRepositoryInterface;
use Domains\Sales\Application\Contract\MessageGatewayInterface;
use Domains\Sales\Automation\Action\CreateFollowupTaskHandler;
use Domains\Sales\Automation\Action\ScheduleFollowupHandler;
use Domains\Sales\Automation\Action\SendMessageHandler;
use Domains\Sales\Automation\Action\UpdateDealHandler;
use Domains\Sales\Automation\Agent\SalesIntelligenceAgent;
use Domains\Sales\Automation\Event\CallCompleted;
use Domains\Sales\Automation\Event\ActionOutcomeMeasured;
use Domains\Sales\Automation\Event\ClientCaseChanged;
use Domains\Sales\Automation\Event\ClientCaseCreated;
use Domains\Sales\Automation\Event\DealCreated;
use Domains\Sales\Automation\Event\DealStageChanged;
use Domains\Sales\Automation\Event\FollowupOverdue;
use Domains\Sales\Automation\Event\LeadChanged;
use Domains\Sales\Automation\Event\LeadCreated;
use Domains\Sales\Automation\Event\SalesEventType;
use Domains\Sales\Automation\Policy\SalesPolicyCatalog;
use Domains\Sales\Automation\Rule\SalesRuleCatalog;
use Kernel\Agent\Contract\AgentContextBuilderInterface;
use Kernel\Module\DomainModuleInterface;
use Kernel\Rule\Contract\RuleContextProviderInterface;

final readonly class SalesDomainModule implements DomainModuleInterface
{
    public function __construct(
        private CrmGatewayInterface $crm,
        private DealRepositoryInterface $deals,
        private MessageGatewayInterface $messages,
        private FollowupRepositoryInterface $followups,
        private RuleContextProviderInterface $ruleContexts,
        private AgentContextBuilderInterface $agentContexts,
    ) {
    }

    public function name(): string
    {
        return 'sales';
    }

    public function eventTypes(): array
    {
        return [
            CallCompleted::TYPE, ActionOutcomeMeasured::TYPE, ClientCaseChanged::TYPE, ClientCaseCreated::TYPE, DealCreated::TYPE,
            DealStageChanged::TYPE, FollowupOverdue::TYPE, LeadChanged::TYPE, LeadCreated::TYPE, ...SalesEventType::all(),
        ];
    }

    public function actionTypes(): array
    {
        return [...CreateFollowupTaskHandler::TYPES, ...UpdateDealHandler::TYPES, ...SendMessageHandler::TYPES, ...ScheduleFollowupHandler::TYPES];
    }

    public function actionHandlers(): array
    {
        return [
            new CreateFollowupTaskHandler($this->crm),
            new UpdateDealHandler($this->deals),
            new SendMessageHandler($this->messages),
            new ScheduleFollowupHandler($this->followups),
        ];
    }

    public function agents(): array
    {
        return [SalesIntelligenceAgent::NAME => SalesIntelligenceAgent::definition()];
    }

    public function agentContextBuilders(): array
    {
        return [SalesIntelligenceAgent::NAME => $this->agentContexts];
    }

    public function ruleContextProvider(): RuleContextProviderInterface
    {
        return $this->ruleContexts;
    }

    public function rules(string $organizationId): array
    {
        return (new SalesRuleCatalog())->rules($organizationId);
    }

    public function policies(string $organizationId): array
    {
        return (new SalesPolicyCatalog())->policies($organizationId);
    }
}
