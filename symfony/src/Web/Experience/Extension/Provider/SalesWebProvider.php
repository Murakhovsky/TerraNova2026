<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Shell\ShellCommandItem;

final class SalesWebProvider implements NavigationProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function serviceId(): string
    {
        return 'salesWebProvider';
    }

    public function navigation(WebExtensionContext $context): array
    {
        $items = [
            new NavigationContribution('sales', 'Sales', '/sales/dashboard', 'SL', 20),
            new NavigationContribution('sales-overview', 'Overview', '/sales/dashboard', priority: 10, parentKey: 'sales'),
            new NavigationContribution('today', 'Today', '/sales/today', priority: 20, parentKey: 'sales'),
            new NavigationContribution('pipeline', 'Pipeline', '/sales/pipeline', priority: 30, parentKey: 'sales'),
            new NavigationContribution('leads', 'Leads', '/sales/leads', priority: 40, parentKey: 'sales'),
            new NavigationContribution('deals', 'Deals', '/sales/deals', priority: 50, parentKey: 'sales'),
            new NavigationContribution('director', 'Director', '/sales/director', priority: 60, parentKey: 'sales'),
            new NavigationContribution('clients', 'Clients', '/client-case/inbox', 'CL', 30),
            new NavigationContribution('inbox', 'Inbox', '/client-case/inbox', priority: 10, parentKey: 'clients'),
            new NavigationContribution('cases', 'Cases', '/client-case', priority: 20, parentKey: 'clients'),
        ];

        if ($context->role === 'admin') {
            $items[] = new NavigationContribution(
                'sales-admin',
                'Sales Admin',
                '/sales/admin',
                priority: 70,
                parentKey: 'sales',
            );
        }

        return $items;
    }

    public function commands(WebExtensionContext $context): array
    {
        $commands = [
            new ShellCommandItem('sales.open', 'Open Sales Overview', '/sales/dashboard', 'navigation', 'Sales'),
            new ShellCommandItem('sales.pipeline', 'Open Sales Pipeline', '/sales/pipeline', 'navigation', 'Sales'),
            new ShellCommandItem('sales.leads', 'Open Leads', '/sales/leads', 'navigation', 'Sales'),
            new ShellCommandItem('sales.clients', 'Open Clients', '/client-case/inbox', 'navigation', 'Sales'),
        ];

        if ($context->role === 'admin') {
            $commands[] = new ShellCommandItem('sales.admin', 'Open Sales Administration', '/sales/admin', 'navigation', 'Admin');
        }

        return $commands;
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition('sales.lead', 'Lead Workspace', '/sales/leads', 'sales.lead', 10),
            new WorkspaceDefinition('sales.deal', 'Deal Workspace', '/sales/deals', 'sales.deal', 20),
        ];
    }
}
