<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use LogicException;
use Domains\Marketing\Application\Contract\MarketingAttributionCandidateReadPort;
use Domains\Marketing\Application\Contract\MarketingAttributionStore;
use Domains\Marketing\Application\Contract\MarketingCommerceAttributionStore;
use Domains\Marketing\Application\Contract\MarketingSalesAttributionReadPort;
use Domains\Marketing\Application\Contract\MarketingCommerceOrderReadPort;
use Domains\Marketing\Domain\MarketingAttributionPolicy as LeadPolicy;
use Domains\Marketing\Domain\MarketingCommerceAttributionPolicy as OrderPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
/** Bounded keyset sweep in one atomic, replayable canonical Action. */
final readonly class MarketingAttributionSweepService
{
    public function __construct(private MarketingCampaignService $campaigns,private MarketingPaidLaunchPlanService $plans,
        private MarketingAttributionCandidateReadPort $candidates,private MarketingSalesAttributionReadPort $leads,
        private MarketingCommerceOrderReadPort $orders,private MarketingAttributionStore $receipts,
        private MarketingCommerceAttributionStore $commerce,private ActiveModuleResolver $modules){}
    public function sweep(TenantContext $actor,string $campaign,?string $planId,string $kind,string $from,string $to,?string $after,string $key,int $maxPages=1):array
    {
        LeadPolicy::window($from,$to);$org=$actor->organizationId()->value();
        if($maxPages<1||$maxPages>5)throw new DomainException('Sweep page budget must be 1–5.');
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing')||!$this->modules->isEnabled($org,'sales')
            ||$this->campaigns->get($actor,$campaign)===null||!in_array($kind,['lead','order'],true))
            throw new DomainException('Active Marketing campaign and Sales source required.');
        $pattern=$kind==='lead'?'/^[1-9][0-9]{0,17}$/D':'/^[a-f0-9]{32}$/D';
        if($after!==null&&!preg_match($pattern,$after))throw new DomainException('Invalid sweep cursor.');
        $plan=$planId===null?null:$this->plans->get($actor,$planId);
        if($plan!==null&&$plan['campaign_id']!==$campaign)throw new DomainException('Paid plan scope mismatch.');
        if($kind==='order')$this->orders->assertAvailable($actor);
        $h=self::requestHash($actor->userId()->value(),$campaign,$planId,$kind,$from,$to,$after,$maxPages);
        return $this->receipts->batch($org,$key,$h,function()use($actor,$org,$campaign,$planId,$plan,$kind,$from,$to,$after,$pattern,$maxPages):array{
            $scanned=0;$recorded=0;$unresolved=0;$pages=0;$next=$after;
            do{
            $pageAfter=$next;
            $page=$this->candidates->page($actor,$kind,$from,$to,$pageAfter,50);
            $ids=$page['ids']??null;$next=$page['next_after']??null;
            if(!is_array($ids)||!array_is_list($ids)||count($ids)>50||!array_key_exists('next_after',$page))
                throw new LogicException('Malformed source page.');
            $cursor=$pageAfter;
            foreach($ids as $id){
                if(!is_string($id)||!preg_match($pattern,$id)||($cursor!==null&&($kind==='lead'?(int)$id<=(int)$cursor:strcmp($id,$cursor)<=0)))
                    throw new LogicException('Unsafe source cursor progression.');
                $cursor=$id;
            }
            if($next!==null&&(count($ids)!==50||$next!==$cursor))throw new LogicException('Malformed next cursor.');
            $pages++;$scanned+=count($ids);
            foreach($ids as $id){
                try{
                    if($kind==='lead'){
                        $lead=$this->leads->lead($actor,$id)??throw new DomainException('Lead unavailable.');
                        if(($lead['lead_id']??null)!==$id||strtolower($lead['status'])==='spam')throw new DomainException('Lead unavailable.');
                        $e=LeadPolicy::evidence($lead,$campaign,$planId);
                        $date=substr($e['created_at'],0,10);
                        if($date<$from||$date>$to)throw new DomainException('Lead moved outside scope.');
                        $this->receipts->record($org,$campaign,$e);
                    }else{
                        $fact=$this->orders->get($actor,$id)??throw new DomainException('Order unavailable.');
                        $e=OrderPolicy::evidence($fact,$org,$id,$campaign,$plan);
                        $date=substr($e['occurred_at'],0,10);
                        if($date<$from||$date>$to)throw new DomainException('Order outside scope.');
                        $this->commerce->record($org,$campaign,$e);
                    }
                    $recorded++;
                }catch(DomainException){$unresolved++;}
            }
            }while($next!==null&&$pages<$maxPages);
            // An exhausted page is not complete attribution; late journey evidence and new inserts require rescan.
            return ['model'=>'attribution-sweep-v1','campaign_id'=>$campaign,'plan_id'=>$planId,'source_kind'=>$kind,
                'window_start'=>$from,'window_end'=>$to,'after'=>$after,'next_after'=>$next,'scanned'=>$scanned,
                'max_pages'=>$maxPages,'pages_processed'=>$pages,
                'recorded_or_already_present'=>$recorded,'unresolved'=>$unresolved,'page_exhausted'=>$next===null,
                'complete_coverage'=>false,'financial_mutation'=>false];
        });
    }
    public static function requestHash(string $actor,string $campaign,?string $plan,string $kind,string $from,string $to,?string $after,int $maxPages):string
    {
        return hash('sha256',json_encode(['attribution-sweep-v1',$actor,$campaign,$plan,$kind,$from,$to,$after,$maxPages],JSON_THROW_ON_ERROR));
    }
}
