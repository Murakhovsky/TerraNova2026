<?php
declare(strict_types=1);

namespace App\Infrastructure\Automation;

use App\Application\System\Command\ExecuteSalesActionCommand;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Event\DomainEvent;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\AgentRunJobHandler;
use Kernel\Rule\Contract\ActionProposalSinkInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class SymfonySalesActionProposalSink implements ActionProposalSinkInterface
{
    private const AGENT_ACTION_PREFIX = 'agent.run.';

    public function __construct(
        private ActionPolicyService $policies,
        private MessageBusInterface $commandBus,
        private JobQueueInterface $legacyJobs,
    ) {
    }

    public function accept(DomainEvent $event, ActionProposal $proposal): void
    {
        if (str_starts_with($proposal->type, self::AGENT_ACTION_PREFIX)) {
            $agentName = substr($proposal->type, strlen(self::AGENT_ACTION_PREFIX));
            $this->legacyJobs->enqueue(
                $event->organizationId,
                AgentRunJobHandler::TYPE,
                [
                    'agent_name' => $agentName,
                    'subject_type' => $proposal->targetType ?? $event->aggregateType,
                    'subject_id' => $proposal->targetId ?? $event->aggregateId,
                    'question' => (string) ($proposal->parameters['question'] ?? 'Recommend the safest next action.'),
                    'context_references' => ['event_id' => $event->id, 'rule_id' => $proposal->sourceId],
                ],
                $event->metadata->correlationId,
                $proposal->idempotencyKey,
                3,
                90,
            );
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
