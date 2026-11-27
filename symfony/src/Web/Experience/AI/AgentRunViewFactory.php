<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
use LogicException;
use Kernel\Action\ActionStatus;
use Kernel\Agent\AgentActionProjection;
use Kernel\Agent\AgentRunProjection;
use Kernel\Agent\Contract\AgentRunReadModelInterface;
use Kernel\Tenant\Model\TenantContext;

final readonly class AgentRunViewFactory
{
    public function __construct(
        private AgentRunReadModelInterface $runs,
        private StructuredAgentResultFactory $results,
        private UIActionResolver $actions,
    ) {
    }

    public function create(
        TenantContext $tenant,
        WebExtensionContext $context,
        AgentRunProjection $run,
    ): AgentRunViewModel {
        if (!AgentWorkspaceAccess::allows($tenant)
            || $tenant->organizationId()->value() !== $run->organizationId
            || $context->organizationId !== $run->organizationId
            || $context->role !== $tenant->role()->value()) {
            throw new LogicException('Agent run scope denied.');
        }
        $agentActions = array_values(array_filter(
            $this->runs->actionsForRun($tenant->organizationId()->value(), $run->id),
            static fn (AgentActionProjection $action): bool =>
                $action->organizationId === $run->organizationId && $action->runId === $run->id,
        ));
        $uiActionsByResource = $this->actionsByEntity($tenant, $context, $agentActions);

        $actionViews = [];
        foreach ($agentActions as $action) {
            $entityKey = strtolower((string) $action->targetType) . ':' . $action->targetId;
            $resourceIds = array_filter([$action->id, $action->approvalId]);
            $resolved = [];
            foreach ($resourceIds as $resourceId) {
                foreach ($uiActionsByResource[$entityKey][$resourceId] ?? [] as $uiAction) {
                    $resolved[$uiAction->id] = $uiAction;
                }
            }

            $actionViews[] = new AgentActionView(
                action: $action,
                stage: $this->stage($action->status),
                uiActions: array_values($resolved),
            );
        }

        return new AgentRunViewModel(
            run: $run,
            result: $this->results->fromRun($run),
            actions: $actionViews,
        );
    }

    /**
     * @param list<AgentActionProjection> $agentActions
     * @return array<string,array<string,list<\App\Web\Experience\Action\UIAction>>>
     */
    private function actionsByEntity(
        TenantContext $tenant,
        WebExtensionContext $context,
        array $agentActions,
    ): array {
        $entities = [];

        foreach ($agentActions as $action) {
            if ($action->targetType === null || $action->targetId === null) {
                continue;
            }

            try {
                $entity = new EntityRef(strtolower($action->targetType), $action->targetId);
            } catch (InvalidArgumentException) {
                continue;
            }

            $entities[$entity->key()] = $entity;
        }

        $byResource = [];
        foreach ($entities as $entity) {
            foreach ($this->actions->resolve(
                $tenant,
                $context,
                $entity,
                UIActionPlacement::AI_PROPOSAL,
            ) as $uiAction) {
                if ($uiAction->resourceId !== null) {
                    $byResource[$entity->key()][$uiAction->resourceId][] = $uiAction;
                }
            }
        }

        return $byResource;
    }

    private function stage(ActionStatus $status): string
    {
        return match ($status) {
            ActionStatus::Proposed, ActionStatus::PendingApproval => 'proposal',
            ActionStatus::Queued => 'approved',
            ActionStatus::Running => 'executing',
            ActionStatus::Completed => 'completed',
            ActionStatus::Failed, ActionStatus::Rejected => 'failed',
        };
    }
}
