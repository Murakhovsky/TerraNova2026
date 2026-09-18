<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use App\Application\Sales\Command\RunSalesAgentCommand;
use App\Application\System\Command\ExecuteSalesActionCommand;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Event\DomainEvent;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Rule\Contract\ActionProposalSinkInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SymfonySalesActionProposalSink implements ActionProposalSinkInterface
{
    private const AGENT_ACTION_PREFIX = 'agent.run.';

    public function __construct(
        private ActionPolicyService $policies,
        private MessageBusInterface $commandBus,
    ) {
    }

    public function accept(DomainEvent $event, ActionProposal $proposal): void
    {
        if (str_starts_with($proposal->type, self::AGENT_ACTION_PREFIX)) {
            $agentName = substr($proposal->type, strlen(self::AGENT_ACTION_PREFIX));
            $this->commandBus->dispatch(new RunSalesAgentCommand(
                organizationId: $event->organizationId,
                agentName: $agentName,
                subjectType: $proposal->targetType ?? $event->aggregateType,
                subjectId: $proposal->targetId ?? $event->aggregateId,
                question: (string) ($proposal->parameters['question'] ?? 'Recommend the safest next action.'),
                correlationId: $event->metadata->correlationId,
                idempotencyKey: $proposal->idempotencyKey,
                contextReferences: ['event_id' => $event->id, 'rule_id' => $proposal->sourceId],
            ));
            return;
        }

        $action = $this->policies->submit(
            $event->organizationId,
            $proposal,
            $event->metadata->correlationId,
        );
        if ($action->status !== ActionStatus::Queued) {
            return;
        }

        $this->commandBus->dispatch(new ExecuteSalesActionCommand(
            $event->organizationId,
            $action->id,
        ));
    }
}
