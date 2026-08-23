<?php
declare(strict_types=1);

namespace Infrastructure\Database\Rule;

use Kernel\Action\ActionProposal;
use Kernel\Event\DomainEvent;
use Kernel\Rule\Contract\ActionProposalSinkInterface;
use Kernel\Policy\Service\ActionPolicyService;
use Kernel\Action\ActionStatus;
use Kernel\Queue\Contract\JobQueueInterface;
use Kernel\Queue\Handler\ActionExecutionJobHandler;
use Kernel\Queue\Handler\AgentRunJobHandler;

final readonly class MysqlActionProposalSink implements ActionProposalSinkInterface
{
    public function __construct(
        private ActionPolicyService $policies,
        private JobQueueInterface $queue,
    ) {}

    public function accept(DomainEvent $event, ActionProposal $proposal): void
    {
        if ($proposal->type === 'agent.run.sales_intelligence') {
            $this->queue->enqueue(
                $event->organizationId,
                AgentRunJobHandler::TYPE,
                [
                    'subject_type' => $proposal->targetType ?? $event->aggregateType,
                    'subject_id' => $proposal->targetId ?? $event->aggregateId,
                    'question' => (string) ($proposal->parameters['question'] ?? 'Recommend the best next sales action.'),
                    'context_references' => ['event_id' => $event->id, 'rule_id' => $proposal->sourceId],
                ],
                $event->metadata->correlationId,
                $proposal->idempotencyKey,
                3,
                90,
            );
            return;
        }

        $action = $this->policies->submit($event->organizationId, $proposal, $event->metadata->correlationId);
        if ($action->status === ActionStatus::Queued) {
            $this->queue->enqueue(
                $event->organizationId,
                ActionExecutionJobHandler::TYPE,
                ['action_id' => $action->id],
                $event->metadata->correlationId,
                'action-execution:' . $action->id,
                5,
                120,
            );
        }
    }
}
