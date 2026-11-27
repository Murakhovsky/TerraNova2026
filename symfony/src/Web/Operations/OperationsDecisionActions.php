<?php
declare(strict_types=1);

namespace App\Web\Operations;

use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use Kernel\Action\Contract\ActionRepositoryInterface;
use Kernel\Approval\Contract\ApprovalRepositoryInterface;
use Kernel\Approval\Contract\ApprovalAuthorityInterface;
use Kernel\Tenant\Model\TenantContext;
use Throwable;

/** Only canonical resolver actions and the existing ApprovalAuthority can enable a decision. */
final readonly class OperationsDecisionActions
{
    public function __construct(
        private UIActionResolver $resolver,
        private ApprovalRepositoryInterface $approvals,
        private ActionRepositoryInterface $actions,
        private ApprovalAuthorityInterface $authority,
    ) {}

    public function available(TenantContext $actor, WebExtensionContext $context, array $row): array
    {
        if ($row['source'] !== 'approval' || $row['status'] !== OperationStatus::WaitingApproval || $row['entity'] === null) return [];
        try {
            $org = $actor->organizationId()->value();
            $approval = $this->approvals->findPending($org, $row['id']);
            if ($approval === null || $approval->organizationId !== $org || $approval->actionId !== $row['actionId']) return [];
            $user = $actor->userId()->value();
            if ($approval->approverType === 'USER' && $approval->approverId !== $user) return [];
            if ($approval->approverType === 'ROLE' && $approval->approverId !== $actor->role()->value()
                && !($approval->approverId === 'manager' && $actor->isManager())) return [];
            if (!in_array($approval->approverType, ['USER','ROLE'], true)) return [];
            $action = $this->actions->find($org, $approval->actionId);
            if ($action === null || $action->organizationId !== $org) return [];
            $this->authority->assertCanDecide($org, $user, $approval, $action);
            return array_values(array_filter(
                $this->resolver->resolve($actor, $context, $row['entity'], UIActionPlacement::NOTIFICATION),
                static fn($a): bool => $a->enabled && $a->resourceId === $approval->id
                    && in_array($a->command, ['operations.approve','operations.reject'], true)
                    && !($a->confirmationContract()?->stepUp ?? false),
            ));
        } catch (Throwable) {
            return []; // permission/read failure must not enable a mutation
        }
    }
}
