<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingFinanceFundingReadPort;
use Domains\Marketing\Application\Contract\MarketingPaidLaunchPlanStore;
use Domains\Marketing\Application\Contract\MarketingPaidReadRepository;
use Domains\Marketing\Domain\MarketingPaidFundingPolicy;
use Domains\Marketing\Domain\MarketingPlanningPolicy;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Tenant\Model\TenantContext;
use Kernel\Tenant\Model\TenantPermissions;

/** Immutable candidate, not a provider-ready payload. No direct Finance or Meta writes. */
final readonly class MarketingPaidLaunchPlanService
{
    public function __construct(private MarketingPaidLaunchPlanStore $plans,private MarketingCampaignService $campaigns,
        private MarketingPlanningService $planning,private MarketingPaidReadRepository $accounts,
        private MarketingFinanceFundingReadPort $finance,private ActiveModuleResolver $modules){}

    public function create(TenantContext $actor,string $campaignId,int $campaignRevision,string $adsetId,int $adsetRevision,
        string $accountId,int $accountRevision,string $start,string $end,string $key):array
    {
        $org=$this->guard($actor);
        $key=MarketingPlanningPolicy::key($key);
        $hash=hash('sha256',json_encode([$actor->userId()->value(),$campaignId,$campaignRevision,$adsetId,$adsetRevision,
            $accountId,$accountRevision,$start,$end],JSON_THROW_ON_ERROR));
        $existing=$this->plans->byKey($org,$key);
        if($existing!==null){
            if(!hash_equals($hash,$existing['request_hash']))throw new DomainException('Paid plan idempotency conflict.');
            return self::project($existing);
        }
        if($start<gmdate('Y-m-d'))throw new DomainException('New funding plan cannot start in the past.');
        $plan=$this->snapshot($actor,$campaignId,$campaignRevision,$adsetId,$adsetRevision,$accountId,$accountRevision,$start,$end);
        return self::project($this->plans->create($org,$actor->userId()->value(),$key,$hash,$plan));
    }

    public function listForCampaign(TenantContext $actor,string $campaign):array
    {
        $org=$this->guard($actor);
        if($this->campaigns->get($actor,$campaign)===null)throw new DomainException('Campaign unavailable.');
        return array_map(self::project(...),$this->plans->listForCampaign($org,$campaign));
    }

    public function get(TenantContext $actor,string $id):array
    {
        return self::project($this->requirePlan($actor,$id));
    }

    public function funding(TenantContext $actor,string $id,string $envelopeId,string $reservationId):array
    {
        $plan=$this->requirePlan($actor,$id);
        MarketingPlanningPolicy::id($envelopeId,'finb-');MarketingPlanningPolicy::id($reservationId,'finr-');
        $result=['plan_id'=>$id,'plan_hash'=>$plan['plan_hash'],'envelope_id'=>$envelopeId,'reservation_id'=>$reservationId,
            'status'=>'SOURCE_STALE','funding_observed'=>false,'finance_revision'=>null,'finance_action_id'=>null,
            'provider_write_authorized'=>false,'paid_activation_approved'=>false,'spend_confirmed'=>false];
        try{$this->assertCurrent($actor,$plan);}catch(DomainException){return $result;}
        $result['status']='FUNDING_NOT_MATCHED';
        try{$observed=$this->finance->observe($actor,$envelopeId,$reservationId);}
        catch(DomainException){$result['status']='FINANCE_UNAVAILABLE';return $result;}
        if($observed===null||!MarketingPaidFundingPolicy::matches($plan,$observed,$envelopeId,$reservationId,gmdate('Y-m-d')))return $result;
        try{$this->assertCurrent($actor,$plan);}catch(DomainException){$result['status']='SOURCE_STALE';return $result;}
        $result['status']='RESERVATION_OBSERVED_NOT_AUTHORIZED';$result['funding_observed']=true;
        $result['finance_revision']=$observed['envelope']['revision'];$result['finance_action_id']=$observed['reservation']['action_id'];
        return $result;
    }

    /** Internal owner read for approved deployment worker; never a Capability output. */
    public function deploymentContext(TenantContext $actor,string $id):array
    {
        $plan=$this->requirePlan($actor,$id);$this->assertCurrent($actor,$plan);
        $org=$actor->organizationId()->value();
        $account=$this->accounts->account($org,$plan['account_id']);
        $adset=null;$audience=null;$creative=null;
        foreach($this->planning->adsets($actor,$plan['campaign_id']) as $row){if($row['id']===$plan['adset_id']){$adset=$row;break;}}
        $c=$adset['content']??[];
        if(($c['optimization']??null)!=='TRAFFIC'||($c['placements']??null)!==['AUTOMATIC']
            ||count($c['creatives']??[])!==1||$plan['account_timezone']!=='UTC'
            ||$plan['period_start']<=gmdate('Y-m-d')){
            throw new DomainException('P0 provider deployment requires future UTC traffic plan with one creative and automatic placements.');
        }
        foreach($this->planning->audiences($actor) as $row){if($row['id']===$c['audience_id']&&$row['revision']===$c['audience_revision']){$audience=$row;break;}}
        $pin=$c['creatives'][0];
        foreach($this->planning->creatives($actor,$plan['campaign_id']) as $row){if($row['id']===$pin['creative_id']&&$row['revision']===$pin['revision']){$creative=$row;break;}}
        if($account===null||$audience===null||$creative===null)throw new DomainException('Deployment source missing.');
        $this->assertCurrent($actor,$plan);
        return ['plan'=>self::project($plan),'provider_account_id'=>$account['ad_account_id'],
            'read_credential_ref'=>$account['credential_ref'],'creative_hash'=>$creative['content_hash'],
            'countries'=>$audience['content']['target_spec']['market_countries']];
    }

    private function requirePlan(TenantContext $actor,string $id):array
    {
        return $this->plans->get($this->guard($actor),MarketingPlanningPolicy::id($id,'mkpl-'))
            ??throw new DomainException('Paid funding plan unavailable.');
    }
    private function assertCurrent(TenantContext $actor,array $plan):void
    {
        $fresh=$this->snapshot($actor,$plan['campaign_id'],$plan['campaign_revision'],$plan['adset_id'],$plan['adset_revision'],
            $plan['account_id'],$plan['account_revision'],$plan['period_start'],$plan['period_end']);
        foreach($fresh as $field=>$value){
            if(($plan[$field]??null)!==$value)throw new DomainException('Paid funding plan source changed.');
        }
    }
    private function snapshot(TenantContext $actor,string $campaignId,int $campaignRevision,string $adsetId,int $adsetRevision,
        string $accountId,int $accountRevision,string $start,string $end):array
    {
        $org=$this->guard($actor);
        MarketingPlanningPolicy::id($campaignId,'mkt-');MarketingPlanningPolicy::id($adsetId,'mkas-');
        MarketingPlanningPolicy::id($accountId,'mads-');
        foreach([$campaignRevision,$adsetRevision,$accountRevision] as $rev)MarketingPlanningPolicy::revision($rev);
        $campaign=$this->campaigns->get($actor,$campaignId);
        if($campaign===null||$campaign['status']!=='DRAFT'||$campaign['revision']!==$campaignRevision)throw new DomainException('Campaign revision stale.');
        $adset=null;
        foreach($this->planning->adsets($actor,$campaignId) as $item){
            if($item['id']===$adsetId&&$item['revision']===$adsetRevision&&$item['status']==='DRAFT'&&!$item['pins_stale']){$adset=$item;break;}
        }
        if($adset===null)throw new DomainException('AdSet or source pins stale.');
        $account=$this->accounts->account($org,$accountId);
        $currency=$adset['content']['currency'];
        if($account===null||$account['provider']!=='meta'||$account['connection_status']!=='READ_ONLY'
            ||$account['revision']!==$accountRevision||$account['currency']!==$currency)throw new DomainException('Meta account revision or currency mismatch.');
        $budget=MarketingPaidFundingPolicy::budget($adset['content']['planned_daily_budget'],$currency,$start,$end);
        return ['campaign_id'=>$campaignId,'campaign_revision'=>$campaignRevision,'adset_id'=>$adsetId,'adset_revision'=>$adsetRevision,
            'adset_content_hash'=>$adset['content_hash'],'account_id'=>$accountId,'account_revision'=>$accountRevision,
            'currency'=>$currency,'account_timezone'=>$account['timezone'],'period_start'=>$start,'period_end'=>$end,...$budget];
    }

    private function guard(TenantContext $actor):string
    {
        $org=$actor->organizationId()->value();
        if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)
            ||!$this->modules->isEnabled($org,'marketing'))throw new DomainException('Marketing manager and active module required.');
        return $org;
    }
    private static function project(array $plan):array
    {
        unset($plan['request_hash']);
        return [...$plan,'state'=>'FUNDING_CANDIDATE','provider_write_authorized'=>false,'paid_activation_approved'=>false];
    }
}
