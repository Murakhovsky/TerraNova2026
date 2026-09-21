<?php

declare(strict_types=1);

namespace App\Command;

use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\ShellNavigationItem;
use App\Web\Experience\Shell\ShellViewModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Routing\RouterInterface;
use Twig\Environment;

#[AsCommand(
    name: 'cos:web:shell:smoke',
    description: 'Validate the canonical COS Workspace Shell runtime.',
)]
final class WorkspaceShellSmokeCommand extends Command
{
    public function __construct(
        private readonly Environment $twig,
        private readonly RouterInterface $router,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $route = $this->router->getRouteCollection()->get('cos_web_workspace_shell_preview');
        if ($route === null || $route->getPath() !== '/dev/shell') {
            $output->writeln('<error>Workspace Shell preview route is unavailable.</error>');

            return Command::FAILURE;
        }

        $shell = new ShellViewModel(
            title: 'Runtime Shell',
            tenantLabel: 'Runtime Tenant',
            userLabel: 'Runtime User',
            userInitials: 'RU',
            activeSection: 'home',
            primaryNavigation: [
                new ShellNavigationItem('home', 'Overview', '/admin', 'HM', true),
            ],
            utilityNavigation: [
                new ShellNavigationItem('cabinet', 'Cabinet', '/cabinet', 'ME'),
            ],
            breadcrumbs: [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Overview'),
            ],
            commands: [
                new ShellCommandItem('navigate.home', 'Open Overview', '/admin'),
            ],
            notificationCount: 2,
            activityCount: 4,
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );

        $html = $this->twig->render('experience/workspace_shell_preview.html.twig', ['shell' => $shell]);

        foreach ([
            'data-controller="workspace-shell"',
            'cos-shell__sidebar',
            'cos-shell__topbar',
            'cos-command__panel',
            'Activity Center',
            'Realtime connection state',
            'Mobile Workspace navigation',
            'Runtime Tenant',
        ] as $marker) {
            if (!str_contains($html, $marker)) {
                $output->writeln(sprintf('<error>Missing Workspace Shell marker: %s</error>', $marker));

                return Command::FAILURE;
            }
        }

        $output->writeln('COS Workspace Shell runtime passed.');

        return Command::SUCCESS;
    }
}
