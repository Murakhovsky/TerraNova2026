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

final readonly class MarketingWebProvider implements NavigationProviderInterface,SearchProviderInterface,CommandProviderInterface,WorkspaceProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher){}
    public function serviceId():string{return 'marketingNavigationContributor';}
    public function navigation(WebExtensionContext $context):array
    {
        return [
            new NavigationContribution('marketing','Marketing','/marketing','MK',22),
            new NavigationContribution('marketing-overview','Overview','/marketing',priority:10,parentKey:'marketing'),
            new NavigationContribution('marketing-campaigns','Campaigns','/marketing/campaigns',priority:20,parentKey:'marketing'),
            new NavigationContribution('marketing-accounts','Paid Media Accounts','/marketing/accounts',priority:30,parentKey:'marketing'),
            new NavigationContribution('marketing-performance','Performance','/marketing/performance',priority:40,parentKey:'marketing'),
            new NavigationContribution('marketing-actions','Action Queue','/marketing/actions',priority:50,parentKey:'marketing'),
        ];
    }
    public function search(WebExtensionContext $context,string $query,int $limit=10):array
    {
        return $this->matcher->match([
            new SearchResult('marketing.search.overview','Marketing Overview','/marketing','workspace','Campaigns and Meta read-only health'),
            new SearchResult('marketing.search.campaigns','Marketing Campaigns','/marketing/campaigns','workspace','Drafts and owner state'),
            new SearchResult('marketing.search.accounts','Meta Ad Accounts','/marketing/accounts','workspace','Verified connections and sync'),
            new SearchResult('marketing.search.performance','Paid Media Performance','/marketing/performance','workspace','Provider observations only'),
            new SearchResult('marketing.search.actions','Marketing Action Queue','/marketing/actions','workspace','Blocked paid writes and incidents'),
        ],$query,$limit);
    }
    public function commands(WebExtensionContext $context):array
    {
        return [
            new ShellCommandItem('marketing.open','Open Marketing','/marketing','navigation','Marketing'),
            new ShellCommandItem('marketing.campaigns','Open Marketing Campaigns','/marketing/campaigns','navigation','Marketing'),
            new ShellCommandItem('marketing.accounts','Open Meta Accounts','/marketing/accounts','navigation','Marketing'),
        ];
    }
    public function workspaces(WebExtensionContext $context):array
    {
        return [
            new WorkspaceDefinition('marketing.overview','Marketing Overview','/marketing',null,10),
            new WorkspaceDefinition('marketing.campaigns','Marketing Campaigns','/marketing/campaigns',null,20),
            new WorkspaceDefinition('marketing.accounts','Paid Media Accounts','/marketing/accounts',null,30),
            new WorkspaceDefinition('marketing.performance','Marketing Performance','/marketing/performance',null,40),
            new WorkspaceDefinition('marketing.actions','Marketing Action Queue','/marketing/actions',null,50),
        ];
    }
}
