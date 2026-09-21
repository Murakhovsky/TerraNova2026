<?php

declare(strict_types=1);

namespace App\Web\Experience\Action;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\RuntimeActionReadModelInterface;
use Kernel\Action\RuntimeActionProjection;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class RuntimeUIActionProvider
{
    public function __construct(private RuntimeActionReadModelInterface $actions)
    {
    }

    /** @return list<UIAction> */
    public function actions(WebExtensionContext $context, ?EntityRef $entity): array
    {
        if ($entity === null) {
            return [];
        }

        $items = [];
        foreach ($this->actions->forEntity(
            $context->organizationId,
            $this->targetTypes($entity->type),
            $entity->id,
            20,
        ) as $action) {
            array_push($items, ...$this->project($action));
        }

        return $items;
    }

    /** @return list<UIAction> */
    public function project(RuntimeActionProjection $action): array
    {
        if (in_array($action->status, [ActionStatus::Completed, ActionStatus::Rejected], true)) {
            return [];
        }

        if ($action->status === ActionStatus::PendingApproval) {
            return $this->approvalActions($action);
        }

        $enabled = in_array($action->status, [ActionStatus::Proposed, ActionStatus::Failed], true);
        $danger = $this->danger($action->riskLevel);
        $verb = match ($action->status) {
            ActionStatus::Failed => 'Retry',
            ActionStatus::Queued => 'Queued',
            ActionStatus::Running => 'Running',
            default => 'Run',
        };

        return [new UIAction(
            id: 'runtime.action.a' . $action->id,
            label: $verb . ' · ' . $this->label($action->type),
            intent: UIActionIntent::Execute,
            icon: 'bolt',
            permission: TenantPermissions::MANAGE,
            enabled: $enabled,
            disabledReason: $enabled ? null : $this->disabledReason($action),
            confirmation: $this->confirmation($action, $danger),
            command: 'operations.execute_action',
            async: true,
            dangerLevel: $danger->value,
            priority: 500,
            placements: [
                UIActionPlacement::WORKSPACE_SECONDARY,
                UIActionPlacement::CONTEXT_MENU,
                UIActionPlacement::MOBILE_MENU,
                UIActionPlacement::AI_PROPOSAL,
            ],
            resourceId: $action->id,
        )];
    }

    /** @return list<UIAction> */
    private function approvalActions(RuntimeActionProjection $action): array
    {
        if (
            $action->approvalId === null
            || !preg_match('/^[a-f0-9]{32}$/', $action->approvalId)
            || strtoupper((string) $action->approvalStatus) !== 'PENDING'
        ) {
            return [new UIAction(
                id: 'runtime.action.a' . $action->id,
                label: 'Awaiting approval · ' . $this->label($action->type),
                intent: UIActionIntent::View,
                icon: 'clock',
                permission: TenantPermissions::MANAGE,
                enabled: false,
                disabledReason: 'Action is waiting for an approval decision.',
                priority: 500,
                placements: [
                    UIActionPlacement::WORKSPACE_SECONDARY,
                    UIActionPlacement::CONTEXT_MENU,
                    UIActionPlacement::MOBILE_MENU,
                    UIActionPlacement::AI_PROPOSAL,
                ],
                resourceId: $action->id,
            )];
        }

        $danger = $this->danger($action->riskLevel);
        $confirmation = $this->confirmation($action, $danger);

        return [
            new UIAction(
                id: 'runtime.approval.a' . $action->approvalId . '.approve',
                label: 'Approve · ' . $this->label($action->type),
                intent: UIActionIntent::Approve,
                icon: 'check',
                permission: TenantPermissions::MANAGE,
                confirmation: $confirmation,
                command: 'operations.approve',
                async: false,
                dangerLevel: $danger->value,
                priority: 480,
                placements: [
                    UIActionPlacement::WORKSPACE_SECONDARY,
                    UIActionPlacement::CONTEXT_MENU,
                    UIActionPlacement::MOBILE_MENU,
                    UIActionPlacement::AI_PROPOSAL,
                    UIActionPlacement::NOTIFICATION,
                ],
                resourceId: $action->approvalId,
            ),
            new UIAction(
                id: 'runtime.approval.a' . $action->approvalId . '.reject',
                label: 'Reject · ' . $this->label($action->type),
                intent: UIActionIntent::Reject,
                icon: 'x',
                permission: TenantPermissions::MANAGE,
                command: 'operations.reject',
                async: false,
                priority: 490,
                placements: [
                    UIActionPlacement::WORKSPACE_SECONDARY,
                    UIActionPlacement::CONTEXT_MENU,
                    UIActionPlacement::MOBILE_MENU,
                    UIActionPlacement::AI_PROPOSAL,
                    UIActionPlacement::NOTIFICATION,
                ],
                resourceId: $action->approvalId,
            ),
        ];
    }

    /** @return list<string> */
    private function targetTypes(string $entityType): array
    {
        $types = [$entityType];
        $parts = explode('.', $entityType);
        $legacy = end($parts);

        if (is_string($legacy) && $legacy !== '' && $legacy !== $entityType) {
            $types[] = $legacy;
        }

        return array_values(array_unique($types));
    }

    private function danger(string $riskLevel): UIActionDangerLevel
    {
        return match (strtoupper(trim($riskLevel))) {
            'MEDIUM', 'CAUTION' => UIActionDangerLevel::Caution,
            'HIGH', 'DESTRUCTIVE' => UIActionDangerLevel::Destructive,
            'CRITICAL' => UIActionDangerLevel::Critical,
            default => UIActionDangerLevel::None,
        };
    }

    private function confirmation(
        RuntimeActionProjection $action,
        UIActionDangerLevel $danger,
    ): ?UIActionConfirmation {
        if ($danger === UIActionDangerLevel::None) {
            return null;
        }

        $message = sprintf(
            'Confirm runtime action "%s" with %s risk.',
            $this->label($action->type),
            strtolower($action->riskLevel),
        );

        return $danger === UIActionDangerLevel::Critical
            ? UIActionConfirmation::stepUp($message, 'Confirm action')
            : UIActionConfirmation::simple($message, 'Confirm action');
    }

    private function disabledReason(RuntimeActionProjection $action): string
    {
        return match ($action->status) {
            ActionStatus::Queued => 'Action is queued for execution.',
            ActionStatus::Running => 'Action is currently running.',
            default => 'Runtime action is unavailable.',
        };
    }

    private function label(string $type): string
    {
        $label = preg_replace('/[._:-]+/', ' ', trim($type)) ?? trim($type);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return ucwords($label);
    }
}
