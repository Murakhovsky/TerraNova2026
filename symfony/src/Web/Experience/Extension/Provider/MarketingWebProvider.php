<?php
declare(strict_types=1);
namespace App\Web\Experience\Extension\Provider;

use App\Web\Experience\Extension\Contract\EntityLinkProviderInterface;
use App\Web\Experience\Extension\Model\EntityLink;
use App\Web\Experience\Unified\Business\BusinessRecordProvider;
use App\Web\Experience\Unified\Business\WorkspaceCapabilityRead;
use App\Web\Experience\Model\EntityRef;
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

final readonly class MarketingWebProvider implements NavigationProviderInterface,SearchProviderInterface,CommandProviderInterface,WorkspaceProviderInterface, BusinessRecordProvider, EntityLinkProviderInterface
{
    public function __construct(private SearchResultMatcher $matcher,private WorkspaceCapabilityRead $read){}
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
        $limit=max(1,min(50,$limit));
        $navigation=$this->matcher->match([
            new SearchResult('marketing.search.overview','Marketing Overview','/marketing','workspace','Campaigns and Meta read-only health'),
            new SearchResult('marketing.search.campaigns','Marketing Campaigns','/marketing/campaigns','workspace','Drafts and owner state'),
            new SearchResult('marketing.search.accounts','Meta Ad Accounts','/marketing/accounts','workspace','Verified connections and sync'),
            new SearchResult('marketing.search.performance','Paid Media Performance','/marketing/performance','workspace','Provider observations only'),
            new SearchResult('marketing.search.actions','Marketing Action Queue','/marketing/actions','workspace','Blocked paid writes and incidents'),
        ], $query, $limit);
        if (trim($query)==='') return $navigation;
        try {
            $page=$this->records($context,'marketing.campaigns',trim($query),1);
            return array_slice([...$page['items'],...$navigation],0,$limit);
        } catch (\Throwable $e) {
            // Unavailable or unauthorized entity reads must not break navigation.
            error_log('cos.workspace.marketing.campaigns.search_unavailable '.$e::class);
            return $navigation;
        }
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
            new WorkspaceDefinition('marketing.campaigns','Marketing Campaigns','/marketing/campaigns','marketing.campaign',20),
            new WorkspaceDefinition('marketing.accounts','Paid Media Accounts','/marketing/accounts',null,30),
            new WorkspaceDefinition('marketing.performance','Marketing Performance','/marketing/performance',null,40),
            new WorkspaceDefinition('marketing.actions','Marketing Action Queue','/marketing/actions',null,50),
        ];
    }

    public function supportsRecords(string $workspaceId):bool { return $workspaceId==='marketing.campaigns'; }

    public function records(WebExtensionContext $context,string $workspaceId,string $query,int $page):array
    {
        if (!$this->supportsRecords($workspaceId)) throw new \InvalidArgumentException('Unsupported workspace.');
        if (preg_match('/^mkt-[a-f0-9]{24}$/D',$query)) {
            $detail=$this->record($context,$workspaceId,new EntityRef('marketing.campaign',$query));
            return ['items'=>$detail===null?[]:[$detail['hit']],'has_more'=>false,'notice'=>null];
        }
        // Marketing search does not currently expose an offset/cursor contract.
        $data=$this->read->query($context,'marketing.campaign.search',['q'=>$query,'limit'=>50]);
        $hits=[];
        foreach ($data['items']??[] as $row) {
            $id=(string)($row['id']??'');
            if (!preg_match('/^mkt-[a-f0-9]{24}$/D',$id)) continue;
            $hits[]=WorkspaceCapabilityRead::hit('marketing.campaign',$id,(string)$row['name'],
                '/marketing/campaigns/'.$id,(string)$row['status']);
        }
        return ['items'=>$hits,'has_more'=>false,'notice'=>count($hits)>=50?'Showing up to 50 campaigns. Narrow your search for other records.':null];
    }

    public function record(WebExtensionContext $context,string $workspaceId,EntityRef $entity):?array
    {
        if (!$this->supportsRecords($workspaceId)||$entity->type!=='marketing.campaign'||!preg_match('/^mkt-[a-f0-9]{24}$/D',$entity->id)) return null;
        $data=$this->read->query($context,'marketing.campaign.get',['id'=>$entity->id]);
        $row=$data['campaign']??null;
        if (!is_array($row)||($row['id']??null)!==$entity->id) return null;
        return [
            'hit'=>WorkspaceCapabilityRead::hit($entity->type,$entity->id,(string)$row['name'],'/marketing/campaigns/'.$entity->id,(string)$row['status']),
            'facts'=>WorkspaceCapabilityRead::facts($row,['status'=>'Status','business_objective'=>'Business objective','revision'=>'Revision','updated_at'=>'Updated']),
            'tables'=>[],
        ];
    }

    public function link(WebExtensionContext $context,EntityRef $entity):?EntityLink
    {
        if ($entity->type!=='marketing.campaign'||!preg_match('/^mkt-[a-f0-9]{24}$/D',$entity->id)) return null;
        return new EntityLink('Campaign','/marketing/campaigns/'.$entity->id);
    }

}
