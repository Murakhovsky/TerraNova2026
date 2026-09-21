<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Action\UIAction;
use App\Web\Experience\Action\UIActionConfirmation;
use App\Web\Experience\Action\UIActionDangerLevel;
use App\Web\Experience\Action\UIActionIntent;
use App\Web\Experience\Action\UIActionPermissionResolver;
use App\Web\Experience\Action\UIActionPlacement;
use App\Web\Experience\Action\UIActionRegistry;
use App\Web\Experience\Action\UIActionResolver;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use Kernel\Identity\Model\OrganizationRole;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Model\Permission;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'cos:web:actions:smoke',
    description: 'Validate the canonical COS Unified UIAction Platform.',
)]
final class UIActionPlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly UIActionRegistry $registry,
        private readonly UIActionResolver $resolver,
        private readonly UIActionPermissionResolver $permissions,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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
        $context = new WebExtensionContext('default', 'admin', 'workspace', 'sales', 'deals');
        $deal = new EntityRef('sales.deal', 'deal-42');

        $registered = $this->registry->actions($context, $deal);
        $registeredIds = array_map(static fn(UIAction $action): string => $action->id, $registered);

        if ($registeredIds !== [
            'sales.deal.change_stage',
            'sales.deal.assign_owner',
            'sales.deal.request_document',
        ]) {
            $output->writeln('<error>Unexpected Sales deal UIAction registry projection.</error>');

            return Command::FAILURE;
        }

        $mobilePrimary = $this->resolver->resolve(
            $tenant,
            $context,
            $deal,
            UIActionPlacement::MOBILE_PRIMARY,
        );

        if (count($mobilePrimary) !== 1
            || $mobilePrimary[0]->id !== 'sales.deal.change_stage'
            || !$mobilePrimary[0]->enabled
            || $mobilePrimary[0]->danger() !== UIActionDangerLevel::Caution
            || $mobilePrimary[0]->confirmationContract()?->confirmLabel !== 'Change stage'
        ) {
            $output->writeln('<error>Mobile primary UIAction projection is invalid.</error>');

            return Command::FAILURE;
        }

        $mobileMenuIds = array_map(
            static fn(UIAction $action): string => $action->id,
            $this->resolver->resolve($tenant, $context, $deal, UIActionPlacement::MOBILE_MENU),
        );

        if ($mobileMenuIds !== ['sales.deal.assign_owner', 'sales.deal.request_document']) {
            $output->writeln('<error>Mobile action menu projection is invalid.</error>');

            return Command::FAILURE;
        }

        $lead = new EntityRef('sales.lead', 'lead-7');
        $leadActions = $this->resolver->resolve($tenant, $context, $lead);
        if (count($leadActions) !== 1
            || $leadActions[0]->id !== 'sales.lead.create_followup'
            || $leadActions[0]->command !== 'sales.create_lead_followup_task'
        ) {
            $output->writeln('<error>Lead UIAction projection is invalid.</error>');

            return Command::FAILURE;
        }

        $unknownPermission = $this->permissions->decide('unknown.capability', $tenant, $deal);
        if ($unknownPermission->allowed || $unknownPermission->reason === null) {
            $output->writeln('<error>Unknown UIAction permission did not fail closed.</error>');

            return Command::FAILURE;
        }

        $manager = new TenantContext(
            UserId::fromString('2'),
            OrganizationId::fromString('default'),
            OrganizationRole::fromString('manager'),
            [
                Permission::fromString(TenantPermissions::ACCESS),
                Permission::fromString(TenantPermissions::MANAGE),
            ],
        );
        if ($this->permissions->decide(TenantPermissions::ADMIN, $manager)->allowed) {
            $output->writeln('<error>Tenant admin permission was granted to a manager.</error>');

            return Command::FAILURE;
        }

        $critical = new UIAction(
            id: 'core.operation.critical',
            label: 'Critical operation',
            intent: UIActionIntent::Danger,
            confirmation: UIActionConfirmation::stepUp('Confirm the critical operation.'),
            dangerLevel: UIActionDangerLevel::Critical->value,
            placements: [UIActionPlacement::WORKSPACE_SECONDARY, UIActionPlacement::MOBILE_MENU],
        );
        if (!$critical->confirmationContract()?->stepUp || !$critical->supportsPlacement(UIActionPlacement::MOBILE_MENU)) {
            $output->writeln('<error>Critical step-up/mobile action contract is invalid.</error>');

            return Command::FAILURE;
        }

        try {
            $this->resolver->resolve(
                $tenant,
                new WebExtensionContext('another-tenant', 'admin'),
                $deal,
            );
            $output->writeln('<error>Cross-tenant UIAction resolution was accepted.</error>');

            return Command::FAILURE;
        } catch (LogicException) {
        }

        $output->writeln('COS Unified UIAction Platform passed.');

        return Command::SUCCESS;
    }
}
