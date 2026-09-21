<?php

declare(strict_types=1);

namespace App\Web\Experience\Dev;

use App\Web\Experience\Shell\ShellBreadcrumb;
use App\Web\Experience\Shell\ShellCommandItem;
use App\Web\Experience\Shell\ShellConnectionState;
use App\Web\Experience\Shell\ShellNavigationItem;
use App\Web\Experience\Shell\ShellViewModel;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class WorkspaceShellPreviewController
{
    public function __construct(private Environment $twig)
    {
    }

    public function __invoke(): Response
    {
        $salesChildren = [
            new ShellNavigationItem('sales-overview', 'Overview', '/sales/dashboard', active: true),
            new ShellNavigationItem('sales-pipeline', 'Pipeline', '/sales/pipeline'),
            new ShellNavigationItem('sales-leads', 'Leads', '/sales/leads'),
        ];

        $primary = [
            new ShellNavigationItem('home', 'Overview', '/admin', 'HM'),
            new ShellNavigationItem('sales', 'Sales', '/sales/dashboard', 'SL', true, $salesChildren),
            new ShellNavigationItem('clients', 'Clients', '/client-case/inbox', 'CL'),
            new ShellNavigationItem('properties', 'Properties', '/property/manage', 'RE'),
            new ShellNavigationItem('cos', 'COS', '/cos/control-center', 'OS'),
        ];

        $shell = new ShellViewModel(
            title: 'Sales Workspace',
            tenantLabel: 'Demo Organization',
            userLabel: 'Workspace Manager',
            userInitials: 'WM',
            activeSection: 'sales',
            primaryNavigation: $primary,
            utilityNavigation: [
                new ShellNavigationItem('cabinet', 'Cabinet', '/cabinet', 'ME'),
            ],
            breadcrumbs: [
                new ShellBreadcrumb('Workspace', '/admin'),
                new ShellBreadcrumb('Sales', '/sales/dashboard'),
                new ShellBreadcrumb('Overview'),
            ],
            commands: [
                new ShellCommandItem('navigate.home', 'Open Workspace Overview', '/admin', 'navigation', 'Section'),
                new ShellCommandItem('navigate.sales', 'Open Sales Overview', '/sales/dashboard', 'navigation', 'Section'),
                new ShellCommandItem('navigate.pipeline', 'Open Sales Pipeline', '/sales/pipeline', 'navigation', 'Section'),
                new ShellCommandItem('navigate.properties', 'Open Properties', '/property/manage', 'navigation', 'Section'),
                new ShellCommandItem('system.activity', 'Open Activity Center', '#activity-center', 'system', 'Global'),
                new ShellCommandItem('system.ai', 'Ask COS AI', '#ai-assistant', 'ai', 'Global'),
            ],
            notificationCount: 3,
            activityCount: 7,
            connectionState: ShellConnectionState::Live,
            aiAvailable: true,
        );

        return new Response(
            $this->twig->render('experience/workspace_shell_preview.html.twig', ['shell' => $shell]),
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Robots-Tag' => 'noindex, nofollow',
                'Cache-Control' => 'no-store, private',
            ],
        );
    }
}
