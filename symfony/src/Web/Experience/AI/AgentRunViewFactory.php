<?php

declare(strict_types=1);

namespace App\Web\Experience\AI;

use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use InvalidArgumentException;
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
        $agentActions = $this->runs->actionsForRun($run->organizationId, $run->id);
        $uiActionsByResource = [];

        foreach ($this->actionsByEntity($tenant, $context, $agentActions) as $resourceId => $uiActions) {
            $uiActionsByResource[$resourceId] = $uiActions;
        }

        $actionViews = [];
        foreach ($agentActions as $action) {
            $resourceIds = array_filter([$action->id, $action->approvalId]);
            $resolved = [];
            foreach ($resourceIds as $resourceId) {
                foreach ($uiActionsByResource[$resourceId] ?? [] as $uiAction) {
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
     * @return array<string,list<\App\Web\Experience\Action\UIAction>>
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
                    $byResource[$uiAction->resourceId][] = $uiAction;
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
