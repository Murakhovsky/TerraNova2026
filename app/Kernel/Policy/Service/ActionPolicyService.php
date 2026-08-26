<?php
declare(strict_types=1);

namespace Kernel\Policy\Service;

use Kernel\Action\Action;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Policy\Contract\PolicyEvaluationRepositoryInterface;
use Kernel\Policy\Contract\PolicyRepositoryInterface;
use Kernel\Policy\PolicyDecision;
use DateTimeImmutable;
use Kernel\Audit\AuditEntry;
use Kernel\Audit\Contract\AuditRepositoryInterface;
use Kernel\Transaction\Contract\TransactionManagerInterface;

final readonly class ActionPolicyService
{
    public function __construct(
        private ActionService $actions,
        private PolicyRepositoryInterface $policies,
        private PolicyEvaluationRepositoryInterface $evaluations,
        private ApprovalRepositoryInterface $approvals,
        private PolicyEngine $engine,
        private TransactionManagerInterface $transactions,
        private ?AuditRepositoryInterface $audit = null,
    ) {}

    public function submit(string $organizationId, ActionProposal $proposal, string $correlationId): Action
    {
        return $this->transactions->transactional(function () use ($organizationId, $proposal, $correlationId): Action {
            $action = $this->actions->propose($organizationId, $proposal, $correlationId, ActionStatus::Proposed);

            // An idempotent duplicate has already passed policy and keeps its current lifecycle state.
            if ($action->status !== ActionStatus::Proposed) return $action;

            $context = ['action' => [
                'type' => $action->type,
                'parameters' => $action->parameters,
                'risk_level' => $action->riskLevel,
                'source_type' => $action->sourceType,
                'target_type' => $action->targetType,
            ]];
            $evaluation = $this->engine->evaluate(
                $action->type,
                $context,
                $this->policies->activeFor($organizationId, $action->type),
            );
            $this->evaluations->save($action, $evaluation, $context);
            $this->audit?->append(new AuditEntry(
                bin2hex(random_bytes(16)), $organizationId, 'POLICY_DECISION', 'SYSTEM', 'policy-engine',
                $action->targetType ?? 'action', $action->targetId ?? $action->id,
                $evaluation->reason,
                [
                    'action' => $action->type,
                    'input_references' => ['action_id' => $action->id, 'policy_id' => $evaluation->policy?->id],
                    'result' => ['decision' => $evaluation->decision->value],
                    'metadata' => ['source_type' => $action->sourceType, 'source_id' => $action->sourceId],
                ],
                $correlationId, new DateTimeImmutable(),
            ));

            match ($evaluation->decision) {
                PolicyDecision::Auto => $this->actions->queue($organizationId, $action->id),
                PolicyDecision::ApprovalRequired => $this->requestApproval($action, $evaluation->reason),
                PolicyDecision::Denied => $this->actions->reject($organizationId, $action->id),
            };

            return $this->actions->find($organizationId, $action->id) ?? $action;
        });
    }

    private function requestApproval(Action $action, string $reason): void
    {
        $this->actions->requestApproval($action->organizationId, $action->id);
        $this->approvals->createFor($action, 'ROLE', 'manager', $reason);
    }
}
