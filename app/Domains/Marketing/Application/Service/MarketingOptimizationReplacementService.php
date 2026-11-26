<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Domains\Marketing\Application\Contract\MarketingOptimizationDraftRepository;
use Domains\Marketing\Application\Contract\MarketingOptimizationReceiptStore;
use Domains\Marketing\Domain\MarketingExperimentPolicy;
use Domains\Marketing\Domain\MarketingPaidFundingPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Creates future immutable local funding candidates, never updates an active Meta object or Finance. */
final readonly class MarketingOptimizationReplacementService
{
    public function __construct(private MarketingBudgetOptimizationService $budget,private MarketingAudienceOptimizationService $audience,
        private MarketingPaidLaunchPlanService $plans,private MarketingPlanningService $planning,private MarketingPlanningPolicy $policy,
        private MarketingOptimizationDraftRepository $drafts,private MarketingOptimizationReceiptStore $receipts,private ActiveModuleResolver $modules){}

    public function prepare(TenantContext $actor,string $account,array $p):array
    {
        $this->guard($actor);
        $keys=array_keys($p);sort($keys,SORT_STRING);
        if($keys!==['first_plan_id','first_snapshot_ref','future_end','future_start','mode','second_plan_id','second_snapshot_ref']
            ||!in_array($p['mode'],['BUDGET','AUDIENCE'],true))throw new DomainException('Exact replacement scope required.');
        $from=MarketingPaidFundingPolicy::date($p['future_start']);$to=MarketingPaidFundingPolicy::date($p['future_end']);
        if($p['future_start']<=gmdate('Y-m-d')||$to<$from||$from->diff($to)->days>30)throw new DomainException('Future bounded UTC replacement required.');
        $args=[$actor,$p['first_plan_id'],$p['first_snapshot_ref'],$p['second_plan_id'],$p['second_snapshot_ref']];
        $preview=$p['mode']==='BUDGET'?$this->budget->preview(...$args):$this->audience->preview(...$args);
        if($preview['account_id']!==$account||$from->diff($to)->days!==MarketingPaidFundingPolicy::date($preview['window_start'])->diff(MarketingPaidFundingPolicy::date($preview['window_end']))->days)
            throw new DomainException('Replacement must retain exact account and historical duration.');
        $items=$preview['items'];$winner=null;
        if($p['mode']==='BUDGET'){
            if($preview['status']!=='REVIEW_BOUNDED_REALLOCATION'||$preview['shift_minor']<=0)
                throw new DomainException('Positive bounded budget recommendation required.');
        }else{
            if($preview['status']!=='REVIEW_OBSERVED_DIFFERENCE'||!is_string($preview['lower_observed_cpc_plan_id']))
                throw new DomainException('Positive planned-audience observation required.');
            foreach($items as $r)if($r['plan_id']===$preview['lower_observed_cpc_plan_id'])$winner=$r;
            if($winner===null)throw new DomainException('Audience winner pin unavailable.');
        }
        $candidates=[];
        foreach($items as $item){
            if($winner!==null&&$item['plan_id']===$winner['plan_id'])continue;
            $plan=$this->plans->get($actor,$item['plan_id']);
            $pin=$this->plans->comparisonPins($actor,$item['plan_id']);
            if($plan['plan_hash']!==$item['plan_hash']||$plan['account_id']!==$account||$plan['account_timezone']!=='UTC')
                throw new DomainException('Replacement source changed.');
            $adset=null;
            foreach($this->planning->adsets($actor,$plan['campaign_id']) as $row)
                if($row['id']===$plan['adset_id']&&$row['revision']===$plan['adset_revision']&&$row['content_hash']===$plan['adset_content_hash']&&!$row['pins_stale']){$adset=$row;break;}
            if($adset===null)throw new DomainException('Exact source AdSet unavailable.');
            $content=array_intersect_key($adset['content'],array_flip(['name','audience_id','audience_revision','creatives','placements','optimization','planned_daily_budget','currency']));
            $daily=$winner===null?$item['candidate_daily_minor']:$item['current_daily_minor'];
            if($winner!==null){$content['audience_id']=$winner['audience_id'];$content['audience_revision']=$winner['audience_revision'];}
            $content['planned_daily_budget']=sprintf('%d.%02d',intdiv($daily,100),$daily%100);
            $content['name']='Optimization replacement '.$item['plan_id'];
            $normalized=$this->policy->adset($content);
            $candidates[]=['source_plan_id'=>$plan['id'],'source_plan_hash'=>$plan['plan_hash'],'campaign_id'=>$plan['campaign_id'],
                'campaign_revision'=>$plan['campaign_revision'],'account_revision'=>$plan['account_revision'],'source_pins'=>$pin,
                'content'=>$normalized,'daily_minor'=>$daily,'lifetime_minor'=>$daily*($from->diff($to)->days+1)];
        }
        if(count($candidates)!==($winner===null?2:1)||($winner===null&&array_sum(array_column($candidates,'daily_minor'))!==$preview['total_daily_minor']))
            throw new DomainException('Replacement budget conservation failed.');
        $decision=['model'=>'optimization-replacement-v1','account_id'=>$account,'parameters'=>$p,'preview'=>$preview,'candidates'=>$candidates];
        return [...$decision,'decision_hash'=>MarketingExperimentPolicy::digest($decision)];
    }

    public function record(TenantContext $actor,string $account,array $p,string $key,string $actionId):array
    {
        $org=$this->guard($actor);$who=$actor->userId()->value();
        if(!preg_match('/^wf:[a-f0-9]{64}$/D',$key)||!preg_match('/^[a-f0-9]{32}$/D',$actionId))
            throw new DomainException('Canonical replacement Action required.');
        $request=self::requestHash($who,$account,$p,$actionId);
        $existing=$this->receipts->byKey($org,$key);
        if($existing!==null)return $this->verified($actor,$existing,$who,$account,$p,$actionId);
        return $this->drafts->atomic($org,$account,[$p['first_plan_id'],$p['second_plan_id']],[$p['first_snapshot_ref'],$p['second_snapshot_ref']],
            function()use($actor,$org,$who,$account,$p,$key,$actionId,$request):array{
                $existing=$this->receipts->byKey($org,$key);
                if($existing!==null)return $this->verified($actor,$existing,$who,$account,$p,$actionId);
                $inputs=$p;$expected=$inputs['decision_hash']??null;unset($inputs['decision_hash']);
                $decision=$this->prepare($actor,$account,$inputs);
                if(!is_string($expected)||!hash_equals($expected,$decision['decision_hash']))throw new DomainException('Approved replacement source changed.');
                $created=[];
                foreach($decision['candidates'] as $i=>$c){
                    $stable='opt-replacement-'.hash('sha256',$key.':'.$i);
                    $adset=$this->drafts->createReplacementAdset($org,$who,$c['campaign_id'],$c['campaign_revision'],$c['content'],$stable.'-adset');
                    $plan=$this->plans->create($actor,$c['campaign_id'],$c['campaign_revision'],$adset['id'],$adset['revision'],$account,
                        $c['account_revision'],$inputs['future_start'],$inputs['future_end'],$stable.'-plan');
                    if($plan['daily_minor']!==$c['daily_minor']||$plan['lifetime_minor']!==$c['lifetime_minor'])throw new DomainException('Replacement amount readback mismatch.');
                    $created[]=['source_plan_id'=>$c['source_plan_id'],'plan_id'=>$plan['id'],'plan_hash'=>$plan['plan_hash'],'adset_id'=>$adset['id'],
                        'daily_minor'=>$plan['daily_minor'],'lifetime_minor'=>$plan['lifetime_minor'],'period_start'=>$plan['period_start'],'period_end'=>$plan['period_end'],
                        'audience_id'=>$c['content']['audience_id'],'audience_revision'=>$c['content']['audience_revision']];
                }
                if($this->prepare($actor,$account,$inputs)['decision_hash']!==$expected)throw new DomainException('Replacement evidence changed during creation.');
                $r=['id'=>'mkod-'.bin2hex(random_bytes(12)),'kind'=>'REPLACEMENT_DRAFT','actor_id'=>$who,'action_id'=>$actionId,
                    'recorded_at'=>(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u'),
                    'evidence_hash'=>$expected,'account_id'=>$account,'parameters'=>$p,'decision'=>$decision,'plans'=>$created,
                    'drafts_recorded'=>true,'provider_mutation'=>false,'financial_mutation'=>false,'paid_activation_approved'=>false];
                return $this->verified($actor,$this->receipts->create($org,$key,$request,$r),$who,$account,$p,$actionId);
            });
    }

    public static function requestHash(string $who,string $account,array $p,string $actionId):string
    {return MarketingExperimentPolicy::digest(['optimization-replacement-record-v1',$who,$account,$p,$actionId]);}

    public function verified(TenantContext $actor,array $row,string $who,string $account,array $p,string $actionId):array
    {
        $this->guard($actor);$r=$row['receipt'];$d=$r['decision'];$copy=$d;unset($copy['decision_hash']);
        if(!hash_equals(self::requestHash($who,$account,$p,$actionId),$row['request_hash'])
            ||!hash_equals(MarketingExperimentPolicy::digest($r),$row['receipt_hash'])
            ||$r['kind']!=='REPLACEMENT_DRAFT'||$r['actor_id']!==$who||$r['action_id']!==$actionId||$r['account_id']!==$account
            ||MarketingExperimentPolicy::digest($r['parameters'])!==MarketingExperimentPolicy::digest($p)
            ||$r['evidence_hash']!==$p['decision_hash']||$d['decision_hash']!==$r['evidence_hash']||$d['decision_hash']!==MarketingExperimentPolicy::digest($copy)
            ||$r['drafts_recorded']!==true||$r['provider_mutation']!==false||$r['financial_mutation']!==false||$r['paid_activation_approved']!==false
            ||count($r['plans'])!==count($d['candidates']))throw new DomainException('Replacement receipt differs from approved Action.');
        foreach($r['plans'] as $i=>$result){
            $c=$d['candidates'][$i];$plan=$this->plans->get($actor,$result['plan_id']);
            if($result['source_plan_id']!==$c['source_plan_id']||$result['audience_id']!==$c['content']['audience_id']
                ||$result['audience_revision']!==$c['content']['audience_revision']||$plan['plan_hash']!==$result['plan_hash']
                ||$plan['campaign_id']!==$c['campaign_id']||$plan['account_id']!==$account||$plan['adset_id']!==$result['adset_id']
                ||$plan['daily_minor']!==$c['daily_minor']||$plan['lifetime_minor']!==$c['lifetime_minor']
                ||$result['daily_minor']!==$plan['daily_minor']||$result['lifetime_minor']!==$plan['lifetime_minor']
                ||$result['period_start']!==$plan['period_start']||$result['period_end']!==$plan['period_end']
                ||$plan['period_start']!==$p['future_start']||$plan['period_end']!==$p['future_end'])throw new DomainException('Native replacement plan differs.');
        }
        return ['receipt_id'=>$r['id'],'receipt_hash'=>$row['receipt_hash'],'decision_hash'=>$r['evidence_hash'],'account_id'=>$account,'mode'=>$p['mode'],
            'plans'=>$r['plans'],'drafts_recorded'=>true,'provider_mutation'=>false,'financial_mutation'=>false,'paid_activation_approved'=>false];
    }

    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)||!$this->modules->isEnabled($org,'marketing'))
            throw new DomainException('Active Marketing manager required.');
        return $org;
    }
}
