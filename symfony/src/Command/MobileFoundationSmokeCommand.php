<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Model\EntityRef;
use App\Web\Experience\Workspace\WorkspaceCompositionResolver;
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
    name: 'cos:web:mobile:smoke',
    description: 'Validate canonical COS mobile foundation and Workspace action projections.',
)]
final class MobileFoundationSmokeCommand extends Command
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
            new EntityRef('sales.deal', 'deal-mobile-42'),
        );

        $primary = array_map(static fn ($action): string => $action->id, $workspace->mobilePrimaryActions);
        $menu = array_map(static fn ($action): string => $action->id, $workspace->mobileMenuActions);

        if ($primary !== ['sales.deal.change_stage']) {
            $output->writeln('<error>Mobile primary action projection is invalid.</error>');

            return Command::FAILURE;
        }

        if ($menu !== ['sales.deal.assign_owner', 'sales.deal.request_document']) {
            $output->writeln('<error>Mobile menu action projection is invalid.</error>');

            return Command::FAILURE;
        }

        $html = $this->twig->render('experience/workspace_platform_fragment.html.twig', [
            'workspace' => $workspace,
        ]);

        foreach ([
            'cos-workspace__mobile-actions',
            'Mobile workspace actions',
            'sales.deal.change_stage',
            'sales.deal.assign_owner',
            'sales.deal.request_document',
            'click->workspace-platform#action',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Mobile Workspace marker is missing: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Mobile Foundation runtime passed.');

        return Command::SUCCESS;
    }
}
