<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
use App\Web\Experience\Workspace\WorkspaceSlot;
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
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:workspace:smoke',
    description: 'Validate the canonical COS Workspace Platform runtime.',
)]
final class WorkspacePlatformSmokeCommand extends Command
{
    public function __construct(
        private readonly WorkspaceCompositionResolver $workspaces,
        private readonly Environment $twig,
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

        $context = new WebExtensionContext(
            organizationId: 'default',
            role: 'admin',
            surface: 'workspace',
            activeSection: 'sales',
            activeItem: 'deals',
        );

        $workspace = $this->workspaces->resolve(
            $tenant,
            $context,
            'sales.deal',
            new EntityRef('sales.deal', 'deal-42'),
        );

        if ($workspace->definition->id !== 'sales.deal' || $workspace->entityKey() !== 'sales.deal:deal-42') {
            $output->writeln('<error>Workspace definition/entity projection is invalid.</error>');

            return Command::FAILURE;
        }

        foreach ([WorkspaceSlot::Sidebar, WorkspaceSlot::Activity, WorkspaceSlot::Ai] as $slot) {
            if (!$workspace->hasSlot($slot)) {
                $output->writeln(sprintf('<error>Sales Workspace extension slot is missing: %s</error>', $slot->value));

                return Command::FAILURE;
            }
        }

        $primaryIds = array_map(static fn ($action): string => $action->id, $workspace->primaryActions);
        $secondaryIds = array_map(static fn ($action): string => $action->id, $workspace->secondaryActions);

        if ($primaryIds !== ['sales.deal.change_stage']) {
            $output->writeln('<error>Workspace primary action projection is invalid.</error>');

            return Command::FAILURE;
        }

        if ($secondaryIds !== ['sales.deal.assign_owner', 'sales.deal.request_document']) {
            $output->writeln('<error>Workspace secondary action projection is invalid.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/workspace_platform_fragment.html.twig', [
            'workspace' => $workspace,
        ]);

        foreach ([
            'data-controller="workspace-platform"',
            'data-workspace-id="sales.deal"',
            'Deal Workspace',
            'cos-workspace__layout',
            'workspace-context-panel',
            'workspace-activity-panel',
            'workspace-ai-context',
            'sales.deal.change_stage',
            'Deal execution surface',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Workspace Platform marker is missing: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Workspace Platform runtime passed.');

        return Command::SUCCESS;
    }
}
