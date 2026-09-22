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

final readonly class GrowthWebProvider implements NavigationProviderInterface,SearchProviderInterface,CommandProviderInterface,WorkspaceProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher) {}

    public function serviceId(): string
    {
        return 'growthNavigationContributor';
    }

    public function navigation(WebExtensionContext $context): array
    {
        return [
            new NavigationContribution('growth','Growth','/growth','GR',15),
            new NavigationContribution('growth-overview','Overview','/growth',priority:10,parentKey:'growth'),
            new NavigationContribution('growth-candidates','Opportunities','/growth/candidates',priority:20,parentKey:'growth'),
            new NavigationContribution('growth-accounts','Accounts','/growth/accounts',priority:30,parentKey:'growth'),
            new NavigationContribution('growth-signals','Signals','/growth/signals',priority:40,parentKey:'growth'),
            new NavigationContribution('growth-collectors','Collectors','/growth/collectors',priority:50,parentKey:'growth'),
        ];
    }

    public function search(WebExtensionContext $context,string $query,int $limit=10): array
    {
        return $this->matcher->match([
            new SearchResult('growth.search.overview','Growth Overview','/growth','workspace','Opportunity intelligence'),
            new SearchResult('growth.search.candidates','Growth Opportunities','/growth/candidates','workspace','Opportunity Candidates'),
            new SearchResult('growth.search.accounts','Growth Accounts','/growth/accounts','workspace','Account Intelligence'),
            new SearchResult('growth.search.signals','Growth Signals','/growth/signals','workspace','Evidence stream'),
            new SearchResult('growth.search.collectors','Growth Collectors','/growth/collectors','workspace','Signal ingestion operations'),
        ],$query,$limit);
    }

    public function commands(WebExtensionContext $context): array
    {
        return [
            new ShellCommandItem('growth.open','Open Growth','/growth','navigation','Growth'),
            new ShellCommandItem('growth.candidates','Open Growth Opportunities','/growth/candidates','navigation','Growth'),
            new ShellCommandItem('growth.accounts','Open Growth Accounts','/growth/accounts','navigation','Growth'),
            new ShellCommandItem('growth.signals','Open Growth Signals','/growth/signals','navigation','Growth'),
            new ShellCommandItem('growth.collectors','Open Growth Collectors','/growth/collectors','navigation','Growth'),
        ];
    }

    public function workspaces(WebExtensionContext $context): array
    {
        return [
            new WorkspaceDefinition('growth.overview','Growth Overview','/growth',null,10),
            new WorkspaceDefinition('growth.candidate','Growth Opportunity','/growth/candidates','growth.candidate',20),
            new WorkspaceDefinition('growth.account','Growth Account','/growth/accounts','growth.account',30),
            new WorkspaceDefinition('growth.signals','Growth Signals','/growth/signals',null,40),
            new WorkspaceDefinition('growth.collectors','Growth Collectors','/growth/collectors',null,50),
        ];
    }
}
