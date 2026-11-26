<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingCommerceOrderReadPort;
use Domains\Marketing\Domain\MarketingCommerceAttributionPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;
/** Read-only modeled allocation, never overwrites exact last-touch receipt or recognized revenue. */
final readonly class MarketingTouchAttributionService
{
    public function __construct(private MarketingCommerceOrderReadPort $source,private MarketingCampaignService $campaigns,
        private MarketingPaidLaunchPlanService $plans,private ActiveModuleResolver $modules){}
    public function report(TenantContext $actor,string $order,string $model):array
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing')||!preg_match('/^[a-f0-9]{32}$/D',$order)
            ||!in_array($model,['first_touch','last_touch','linear'],true))throw new DomainException('Invalid journey report scope.');
        $this->source->assertAvailable($actor);$fact=$this->source->get($actor,$order);
        $p=$fact['placement']??null;$h=$fact['touch_history']??null;
        $out=['order_id'=>$order,'model'=>$model,'status'=>'UNAVAILABLE','history_hash'=>null,'currency'=>null,'placed_total_minor'=>null,
            'touch_count'=>0,'allocations'=>[],'unassigned_minor'=>null,'recognized_revenue'=>null,'causal_attribution'=>false];
        if(!is_array($p)||($p['organization_id']??null)!==$org||($p['order_id']??null)!==$order
            ||($p['schema_version']??null)!=='1.0.0'||($p['revision']??null)!==1||($p['event_type']??null)!=='sales.commerce.order.placed'
            ||($p['channel']??null)!=='shopify'||($p['provenance']??null)!=='shopify_admin_read'||!is_array($h))return $out;
        if(($h['state']??null)==='conflict')return [...$out,'status'=>'CONFLICT'];
        if(($h['state']??null)!=='observed'||($h['model']??null)!=='shopify-journey-moments-v1'
            ||!is_string($h['evidence_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$h['evidence_hash']))return $out;
        $end=MarketingCommerceAttributionPolicy::time($p['occurred_at']??null);
        $total=$p['order_total']??null;$currency=$p['currency']??null;
        if(!is_string($total)||!preg_match('/^(0|[1-9][0-9]{0,11})\.[0-9]{2}$/D',$total)||!in_array($currency,['USD','EUR','UAH'],true))
            throw new DomainException('Invalid placed commercial value.');
        $touches=$h['touches']??null;
        if(!is_array($touches)||!array_is_list($touches)||count($touches)<1||count($touches)>500)throw new DomainException('Invalid native history.');
        $ids=[];$lastTime=null;
        foreach($touches as $t){
            if(!is_array($t)||!is_string($t['touch_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',$t['touch_id'])||isset($ids[$t['touch_id']])
                ||!array_key_exists('tags',$t))throw new DomainException('Invalid native touch.');
            $at=MarketingCommerceAttributionPolicy::time($t['visit_at']??null);
            if($at>$end||$at<$end->modify('-30 days')||($lastTime!==null&&$at<$lastTime))throw new DomainException('Invalid native touch order.');
            $ids[$t['touch_id']]=$t;$lastTime=$at;
        }
        $first=$h['first_touch']??null;$last=$h['last_touch']??null;
        if(!is_string($first)||!is_string($last)||!isset($ids[$first],$ids[$last])
            ||$ids[$first]['visit_at']!==$touches[0]['visit_at']||$ids[$last]['visit_at']!==$touches[count($touches)-1]['visit_at']
            ||(count($touches)>1&&$first===$last))throw new DomainException('Invalid native touch endpoints.');
        $normalized=['model'=>$h['model'],'first_touch'=>$first,'last_touch'=>$last,'touches'=>$touches];
        if(!hash_equals($h['evidence_hash'],hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR))))throw new DomainException('Touch history hash mismatch.');
        $selected=$model==='linear'?$touches:[$ids[$model==='first_touch'?$first:$last]];
        $minor=(int)str_replace('.','',$total);$base=intdiv($minor,count($selected));$remainder=$minor%count($selected);
        $alloc=[];$unknown=0;
        foreach($selected as $i=>$t){
            $amount=$base+($i<$remainder?1:0);$tags=$t['tags'];$campaign=null;$plan=null;
            if(is_array($tags)&&is_string($tags['utm_campaign']??null)&&is_string($tags['utm_source']??null)&&is_string($tags['utm_medium']??null)
                &&preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$tags['utm_source'])&&preg_match('/^[a-z0-9][a-z0-9_.-]{0,63}$/D',$tags['utm_medium'])){
                try{
                    $tag=$tags['utm_campaign'];
                    if(preg_match('/^mkt-[a-f0-9]{24}$/D',$tag)&&$this->campaigns->get($actor,$tag)!==null)$campaign=$tag;
                    elseif(preg_match('/^mkpl-[a-f0-9]{24}$/D',$tag)){
                        $pinned=$this->plans->get($actor,$tag);$date=substr($t['visit_at'],0,10);
                        if($tags['utm_source']==='meta'&&$tags['utm_medium']==='paid_social'&&$date>=$pinned['period_start']&&$date<=$pinned['period_end']
                            &&$this->campaigns->get($actor,$pinned['campaign_id'])!==null){$campaign=$pinned['campaign_id'];$plan=$tag;}
                    }
                }catch(DomainException){$campaign=null;$plan=null;}
            }
            if($campaign===null){$unknown+=$amount;continue;}
            $k=$campaign.'/'.$plan.'/'.$tags['utm_source'].'/'.$tags['utm_medium'];
            $alloc[$k]??=['campaign_id'=>$campaign,'plan_id'=>$plan,'source'=>$tags['utm_source'],'medium'=>$tags['utm_medium'],'minor'=>0,'touches'=>0];
            $alloc[$k]['minor']+=$amount;$alloc[$k]['touches']++;
        }
        ksort($alloc,SORT_STRING);
        return [...$out,'status'=>'MODELED_PROVIDER_HISTORY','history_hash'=>$h['evidence_hash'],'currency'=>$currency,'placed_total_minor'=>$minor,
            'touch_count'=>count($touches),'allocations'=>array_values($alloc),'unassigned_minor'=>$unknown];
    }
}
