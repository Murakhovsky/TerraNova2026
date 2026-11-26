<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingAttributionStore;
use Domains\Marketing\Application\Contract\MarketingSalesAttributionReadPort;
use Domains\Marketing\Application\Contract\MarketingFinanceAnalyticsReadPort;
use Domains\Marketing\Domain\MarketingAttributionPolicy as Policy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

final readonly class MarketingAnalyticsService
{
    public function __construct(private MarketingCampaignService $campaigns,private MarketingPaidLaunchPlanService $plans,
        private MarketingPaidOperationsReadService $operations,private MarketingPaidReadService $paid,private MarketingAttributionStore $store,
        private MarketingSalesAttributionReadPort $sales,private MarketingFinanceAnalyticsReadPort $finance,private ActiveModuleResolver $modules){}
    private function scope(TenantContext $actor,string $campaign,?string $plan):?array
    {
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($actor->organizationId()->value(),'marketing')||$this->campaigns->get($actor,$campaign)===null)
            throw new DomainException('Active tenant Marketing campaign required.');
        if($plan===null)return null;
        $p=$this->plans->get($actor,$plan);
        if($p['campaign_id']!==$campaign)throw new DomainException('Paid plan campaign mismatch.');
        return $p;
    }
    public function tracking(TenantContext $actor,string $campaign,?string $plan,string $source,string $medium):array
    {
        $this->scope($actor,$campaign,$plan);
        $e=Policy::evidence(['lead_id'=>'1','created_at'=>'2000-01-01 00:00:00','utm_campaign'=>$plan??$campaign,
            'utm_source'=>$source,'utm_medium'=>$medium],$campaign,$plan);
        $tags=array_intersect_key($e,array_flip(['utm_source','utm_medium','utm_campaign']));
        return ['campaign_id'=>$campaign,'plan_id'=>$plan,'tags'=>$tags,'query_string'=>http_build_query($tags,'','&',PHP_QUERY_RFC3986),'model'=>Policy::VERSION];
    }
    /** Bounded human capture; no caller-supplied outcome or attribution identity. */
    public function capture(TenantContext $actor,string $campaign,?string $plan,array $leadIds,string $key):array
    {
        $p=$this->scope($actor,$campaign,$plan);
        if(!array_is_list($leadIds)||count($leadIds)<1||count($leadIds)>50)throw new DomainException('Choose 1-50 distinct native lead IDs.');
        $out=[];
        foreach($leadIds as $id){
            if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,17}$/D',$id))throw new DomainException('Invalid lead reference.');
        }
        if(count(array_unique($leadIds))!==count($leadIds))throw new DomainException('Duplicate lead reference.');
        sort($leadIds,SORT_STRING);
        $hash=hash('sha256',json_encode([$actor->userId()->value(),$campaign,$plan,$leadIds],JSON_THROW_ON_ERROR));
        return $this->store->batch($actor->organizationId()->value(),$key,$hash,function()use($actor,$campaign,$plan,$leadIds,$out,$p):array{
        foreach($leadIds as $id){
            try{
                $l=$this->sales->lead($actor,$id)??throw new DomainException('Native lead missing.');
                if(strtolower($l['status'])==='spam')throw new DomainException('Spam lead excluded.');
                $e=Policy::evidence($l,$campaign,$plan);Policy::assertLeadPlanWindow($e,$p);
                $r=$this->store->record($actor->organizationId()->value(),$campaign,$e);
                $out[]=['lead_id'=>$id,'state'=>'RECORDED','evidence_id'=>$r['id']];
            }catch(DomainException){$out[]=['lead_id'=>$id,'state'=>'REJECTED','evidence_id'=>null];}
        }
        return ['model'=>Policy::VERSION,'results'=>$out,'financial_mutation'=>false];
        });
    }
    /** Bounded non-mutating discovery; changing Sales lists cannot prove complete coverage. */
    public function discover(TenantContext $actor,string $campaign,?string $plan,string $from,string $to,int $offset):array
    {
        Policy::window($from,$to);$p=$this->scope($actor,$campaign,$plan);
        if($offset<0||$offset>99900)throw new DomainException('Invalid discovery offset.');
        $rows=$this->sales->page($actor,$offset,50);$matches=[];
        foreach($rows as $r){
            $date=substr($r['created_at'],0,10);
            if($date<$from||$date>$to)continue;
            $l=$this->sales->lead($actor,$r['lead_id']);if($l===null)continue;
            try{$e=Policy::evidence($l,$campaign,$plan);Policy::assertLeadPlanWindow($e,$p);}catch(DomainException){continue;}
            if($l['status']!=='spam')$matches[]=$e;
        }
        return ['model'=>Policy::VERSION,'matches'=>$matches,'scanned'=>count($rows),
            'next_offset'=>count($rows)===50&&$offset<99900?$offset+50:null,'complete_coverage'=>false];
    }
    public function report(TenantContext $actor,string $campaign,?string $plan,string $from,string $to,?string $snapshot,?string $reservation):array
    {
        Policy::window($from,$to);$p=$this->scope($actor,$campaign,$plan);
        $org=$actor->organizationId()->value();
        if($p===null&&($snapshot!==null||$reservation!==null))throw new DomainException('Paid plan required for provider or Finance facts.');
        $rows=$this->store->list($org,$campaign,$from,$to,501,0);
        if(count($rows)>500)throw new DomainException('Analytics cohort exceeds 500 evidence records; narrow window.');
        $channels=[];$deals=[];$wonIds=[];$boundPlans=[];$value=[];$leads=0;$qualified=0;$won=0;$missing=0;$drift=0;$unknownValue=0;$linked=0;
        foreach($rows as $r){
            if($plan!==null&&$r['plan_id']!==$plan)continue;
            $l=$this->sales->lead($actor,$r['lead_id']);
            if($l===null){$missing++;continue;}
            try{
                $e=Policy::evidence($l,$campaign,$r['plan_id']);$bound=$p;
                if($bound===null&&$r['plan_id']!==null)$bound=$boundPlans[$r['plan_id']]??=$this->scope($actor,$campaign,$r['plan_id']);
                Policy::assertLeadPlanWindow($e,$bound);
            }catch(DomainException){$drift++;continue;}
            if($e['evidence_hash']!==$r['evidence_hash']){$drift++;continue;}
            if(strtolower($l['status'])==='spam')continue;
            $leads++;$key=$r['utm_source'].'/'.$r['utm_medium'];
            $channels[$key]??=['source'=>$r['utm_source'],'medium'=>$r['utm_medium'],'leads'=>0,'qualified_leads'=>0,'won_deals'=>0];
            $channels[$key]['leads']++;
            if(strtolower($l['status'])==='qualified'){$qualified++;$channels[$key]['qualified_leads']++;}
            $d=$l['deal'];if(!is_array($d))continue;
            if(!isset($deals[$d['deal_id']])){$deals[$d['deal_id']]=true;$linked++;}
            if(isset($wonIds[$d['deal_id']])||!Policy::wonWithin($d,$r['created_at'],$to))continue;
            $wonIds[$d['deal_id']]=true;$won++;$channels[$key]['won_deals']++;
            $c=$d['currency'];$v=Policy::minor($d['deal_value']);
            if(!in_array($c,['USD','EUR','UAH','PLN'],true)||$v===null){$unknownValue++;continue;}
            $value[$c]=($value[$c]??0)+$v;
        }
        ksort($channels);ksort($value);$values=[];foreach($value as $c=>$v)$values[]=['currency'=>$c,'minor'=>$v];
        $media=null;$settlement=null;
        if($snapshot!==null){
            if($p['period_start']!==$from||$p['period_end']!==$to)throw new DomainException('Exact paid plan window required.');
            $proof=$this->operations->compareSpend($actor,$plan,$snapshot);
            foreach($this->paid->insights($actor,$p['account_id'],'campaign',$from,$to) as $row){
                if($row['id']===$snapshot&&$row['revision']===$proof['source_revision']){$media=Policy::media($row);break;}
            }
            if($media===null)throw new DomainException('Source revision changed or missing.');
            $current=$this->paid->spendEvidence($actor,$p['account_id'],$snapshot);
            if($current['source_revision']!==$media['source_revision'])throw new DomainException('Media evidence changed during report.');
            $media['source_hash']=$current['source_hash'];
            $media['attribution_hash']=$current['attribution_hash'];
            $media['timezone']='UTC';
        }
        if($reservation!==null){
            if($p['period_start']!==$from||$p['period_end']!==$to)throw new DomainException('Exact Finance plan window required.');
            $settlement=$this->finance->settlement($actor,$plan,$p['plan_hash'],$reservation);
        }
        // Media and native Sales are separate observation systems; captured cohort is not exhaustive.
        // No ROAS/CAC claim, because no complete attribution coverage or recognized revenue proof.
        return ['campaign_id'=>$campaign,'plan_id'=>$plan,'window_start'=>$from,'window_end'=>$to,'model'=>Policy::VERSION,
            'coverage'=>'CAPTURED_NATIVE_LEADS_ONLY','sales_calendar'=>'NATIVE_STORED_CALENDAR','sales_cutoff'=>$to,
            'leads'=>$leads,'qualified_leads'=>$qualified,'linked_deals'=>$linked,'won_deals'=>$won,
            'lead_to_won_percent'=>Policy::ratio($won,$leads,100),'won_deal_value'=>$values,'unknown_value_count'=>$unknownValue,
            'missing_source_count'=>$missing,'source_drift_count'=>$drift,'channels'=>array_values($channels),'media'=>$media,'settlement'=>$settlement,
            'cost_per_captured_lead'=>$media===null||$missing>0||$drift>0?null:Policy::ratio((float)$media['reported_spend_decimal'],$leads),
            'cost_per_captured_won_deal'=>$media===null||$missing>0||$drift>0?null:Policy::ratio((float)$media['reported_spend_decimal'],$won),
            'recognized_revenue_confirmed'=>false,'roas'=>null,'profit'=>null,'causal_attribution'=>false];
    }
}
