<?php

declare(strict_types=1);

namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\CommandProviderInterface;
use App\Web\Experience\Extension\Contract\NavigationProviderInterface;
use App\Web\Experience\Extension\Contract\SearchProviderInterface;
use App\Web\Experience\Extension\Contract\WorkspaceProviderInterface;
use App\Web\Experience\Extension\Model\NavigationContribution;
use App\Web\Experience\Extension\Model\SearchResult;
use App\Web\Experience\Extension\Model\WebExtensionContext;
use App\Web\Experience\Extension\Model\WorkspaceDefinition;
use App\Web\Experience\Search\SearchResultMatcher;
use App\Web\Experience\Shell\ShellCommandItem;

final class SalesWebProvider implements NavigationProviderInterface, SearchProviderInterface, CommandProviderInterface, WorkspaceProviderInterface
{
    public function __construct(private readonly SearchResultMatcher $matcher)
    {
    }

    public function serviceId(): string
    {
        return 'salesNavigationContributor';
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

    public function search(WebExtensionContext $context, string $query, int $limit = 10): array
    {
        return $this->matcher->match([
            new SearchResult('sales.search.overview', 'Sales Overview', '/sales/dashboard', 'workspace', 'Sales dashboard'),
            new SearchResult('sales.search.today', 'Sales Today', '/sales/today', 'workspace', 'Today queue'),
            new SearchResult('sales.search.pipeline', 'Sales Pipeline', '/sales/pipeline', 'workspace', 'Pipeline'),
            new SearchResult('sales.search.leads', 'Lead Workspace', '/sales/leads', 'workspace', 'Sales leads'),
            new SearchResult('sales.search.deals', 'Deal Workspace', '/sales/deals', 'workspace', 'Sales deals'),
            new SearchResult('sales.search.clients', 'Client Inbox', '/client-case/inbox', 'workspace', 'Clients'),
            new SearchResult('sales.search.director', 'Sales Director', '/sales/director', 'workspace', 'Sales analytics'),
        ], $query, $limit);
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
