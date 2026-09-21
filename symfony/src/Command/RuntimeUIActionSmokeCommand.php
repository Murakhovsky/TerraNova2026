<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Action\RuntimeUIActionProvider;
use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionDangerLevel;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionRegistry;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use DateTimeImmutable;
use Kernel\Action\ActionProposal;
use Kernel\Action\ActionStatus;
use Kernel\Action\RuntimeActionProjection;
use Kernel\Action\Service\ActionService;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:web:runtime-actions:smoke',
    description: 'Validate runtime-generated Kernel Actions projected through the canonical UIAction registry.',
)]
final class RuntimeUIActionSmokeCommand extends Command
{
    public function __construct(
        private readonly ActionService $actionService,
        private readonly UIActionRegistry $registry,
        private readonly UIActionResolver $resolver,
        private readonly RuntimeUIActionProvider $runtimeActions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $targetId = 'wave12-11-' . bin2hex(random_bytes(4));
        $action = $this->actionService->propose(
            'default',
            new ActionProposal(
                type: 'sales.workflow.followup',
                targetType: 'deal',
                targetId: $targetId,
                parameters: ['source' => 'wave12.11.smoke'],
                sourceType: 'WORKFLOW',
                sourceId: 'wave12.11',
                executionMode: 'MANUAL',
                riskLevel: 'LOW',
                idempotencyKey: 'wave12.11.' . bin2hex(random_bytes(8)),
            ),
            'wave12.11.smoke.' . bin2hex(random_bytes(6)),
        );

        $context = new WebExtensionContext(
            organizationId: 'default',
            role: 'admin',
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'deals',
        );
        $entity = new EntityRef('sales.deal', $targetId);

        $projected = null;
        foreach ($this->registry->actions($context, $entity) as $candidate) {
            if ($candidate->id === 'runtime.action.a' . $action->id) {
                $projected = $candidate;
                break;
            }
        }

        if (!$projected instanceof UIAction
            || $projected->resourceId !== $action->id
            || $projected->command !== 'operations.execute_action'
            || !$projected->supportsPlacement(UIActionPlacement::WORKSPACE_SECONDARY)
        ) {
            $output->writeln('<error>Runtime Action did not enter the canonical UIAction registry.</error>');

            return Command::FAILURE;
        }

        $tenant = new TenantContext(
            UserId::fromString('1'),
            OrganizationId::fromString('default'),
            OrganizationRole::fromString('admin'),
            [
                Permission::fromString(TenantPermissions::ACCESS),
                Permission::fromString(TenantPermissions::MANAGE),
                Permission::fromString(TenantPermissions::ADMIN),
            ],
        );

        $resolved = $this->resolver->resolve(
            $tenant,
            $context,
            $entity,
            UIActionPlacement::WORKSPACE_SECONDARY,
        );
        $resolvedRuntime = null;
        foreach ($resolved as $candidate) {
            if ($candidate->id === $projected->id) {
                $resolvedRuntime = $candidate;
                break;
            }
        }

        if (!$resolvedRuntime instanceof UIAction || !$resolvedRuntime->enabled) {
            $output->writeln('<error>Runtime UIAction did not pass tenant-aware resolver projection.</error>');

            return Command::FAILURE;
        }

        $approvalId = str_repeat('b', 32);
        $approvalProjection = new RuntimeActionProjection(
            id: str_repeat('a', 32),
            organizationId: 'default',
            type: 'sales.workflow.high_risk',
            targetType: 'deal',
            targetId: $targetId,
            sourceType: 'WORKFLOW',
            sourceId: 'wave12.11',
            status: ActionStatus::PendingApproval,
            executionMode: 'APPROVAL_REQUIRED',
            riskLevel: 'CRITICAL',
            createdAt: new DateTimeImmutable(),
            approvalId: $approvalId,
            approvalStatus: 'PENDING',
        );

        $approvalActions = $this->runtimeActions->project($approvalProjection);
        if (count($approvalActions) !== 2) {
            $output->writeln('<error>Pending approval did not project approve/reject UIActions.</error>');

            return Command::FAILURE;
        }

        [$approve, $reject] = $approvalActions;
        if ($approve->command !== 'operations.approve'
            || $approve->resourceId !== $approvalId
            || $approve->danger() !== UIActionDangerLevel::Critical
            || !$approve->confirmationContract()?->stepUp
            || $reject->command !== 'operations.reject'
            || $reject->resourceId !== $approvalId
        ) {
            $output->writeln('<error>Runtime approval UIAction contract is invalid.</error>');

            return Command::FAILURE;
        }

        $queued = $this->runtimeActions->project(new RuntimeActionProjection(
            id: str_repeat('c', 32),
            organizationId: 'default',
            type: 'sales.workflow.followup',
            targetType: 'deal',
            targetId: $targetId,
            sourceType: 'WORKFLOW',
            sourceId: 'wave12.11',
            status: ActionStatus::Queued,
            executionMode: 'AUTO',
            riskLevel: 'LOW',
            createdAt: new DateTimeImmutable(),
        ));

        if (count($queued) !== 1 || $queued[0]->enabled || $queued[0]->disabledReason === null) {
            $output->writeln('<error>Queued runtime Action did not project a disabled lifecycle state.</error>');

            return Command::FAILURE;
        }

        $terminal = $this->runtimeActions->project(new RuntimeActionProjection(
            id: str_repeat('d', 32),
            organizationId: 'default',
            type: 'sales.workflow.followup',
            targetType: 'deal',
            targetId: $targetId,
            sourceType: 'WORKFLOW',
            sourceId: 'wave12.11',
            status: ActionStatus::Completed,
            executionMode: 'AUTO',
            riskLevel: 'LOW',
            createdAt: new DateTimeImmutable(),
        ));

        if ($terminal !== []) {
            $output->writeln('<error>Terminal runtime Action leaked into the actionable UI registry.</error>');

            return Command::FAILURE;
        }

        $output->writeln('COS runtime-generated UIAction projection passed.');

        return Command::SUCCESS;
    }
}
