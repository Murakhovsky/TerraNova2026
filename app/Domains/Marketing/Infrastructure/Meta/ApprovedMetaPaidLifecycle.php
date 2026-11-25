<?php
declare(strict_types=1);
namespace Domains\Marketing\Infrastructure\Meta;

use DomainException;
use Domains\Marketing\Application\Contract\MarketingPaidExposureClaimPort;
use Domains\Marketing\Application\Service\MarketingPaidLaunchPlanService;
use Domains\Marketing\Domain\MarketingPaidLifecyclePolicy;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal;
use Kernel\Action\Action;
use Kernel\Tenant\Model\TenantContext;
use Throwable;

/** Canonical handler-only implementation; no generic API invocation. */
final readonly class ApprovedMetaPaidLifecycle
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MetaPaidExecutionProfiles $profiles,
        private MetaPaidWriteAdapter $meta,private MysqlMarketingPaidOperationJournal $journal,private MarketingPaidExposureClaimPort $finance){}

    public function execute(Action $action,TenantContext $actor):array
    {
        $org=$actor->organizationId()->value();$id=(string)$action->targetId;$p=$action->parameters;
        $operation=match($action->type){
            'marketing.paid.meta.campaign.create_paused'=>'DEPLOY_PAUSED',
            'marketing.paid.meta.campaign.activate'=>'ACTIVATE',
            'marketing.paid.meta.campaign.pause'=>'PAUSE',
            default=>throw new DomainException('Unknown Meta lifecycle Action.')
        };
        $plan=$this->plans->get($actor,$id);
        if(!hash_equals($plan['plan_hash'],$p['plan_hash']))throw new DomainException('Approved paid plan changed.');
        $profile=$this->profiles->accepted($org,$plan,$p['configuration_hash'],$operation==='ACTIVATE');
        $account=$profile['provider_account_id'];$ref=$profile['credential_ref'];
        $categories=$profile['special_ad_categories'];$categoryCountries=$profile['special_ad_category_country'];
        // Pause never requires fresh marketing pins or a still-active Finance budget.
        $ctx=null;
        if($operation!=='PAUSE'){
            $ctx=$this->plans->deploymentContext($actor,$id);
            if($categories!==[]){$declared=$categoryCountries;$target=$ctx['countries'];sort($declared,SORT_STRING);sort($target,SORT_STRING);
                if($declared!==$target)throw new DomainException('Special category countries differ from pinned audience.');}
            if($ctx['provider_account_id']!==$account||$ctx['read_credential_ref']===$ref||$ctx['creative_hash']!==$profile['creative_hash']){
                throw new DomainException('Accepted provider identity, credential or Creative binding changed.');
            }
        }
        $requestHash=self::commandHash($action->type,$p);
        $command=$this->journal->beginCommand($org,$id,$operation,$action->id,$requestHash);
        if(!$command['fresh'])throw new DomainException('Command already admitted; no external replay.');
        try{
            if($operation==='DEPLOY_PAUSED'){
                $campaign=$this->stage($org,$id,$action,'CREATE_CAMPAIGN',fn()=>$this->meta->campaign($org,$account,$ref,'COS '.$id,$categories,$categoryCountries),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'campaign',$remote,'PAUSED'));
                MarketingPaidLifecyclePolicy::assertSpecialCategory($this->meta->read($org,$account,$ref,'campaign',$campaign),$categories,$categoryCountries);
                $adset=$this->stage($org,$id,$action,'CREATE_ADSET',fn()=>$this->meta->adset($org,$account,$ref,$campaign,'COS set '.$id,
                    $plan['lifetime_minor'],$plan['period_start'].'T00:00:00Z',
                    \Domains\Marketing\Domain\MarketingPaidFundingPolicy::date($plan['period_end'])->modify('+1 day')->format('Y-m-d').'T00:00:00Z',$ctx['countries']),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'adset',$remote,'PAUSED'));
                $ad=$this->stage($org,$id,$action,'CREATE_AD',fn()=>$this->meta->ad($org,$account,$ref,$adset,$profile['creative_id'],'COS ad '.$id),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'ad',$remote,'PAUSED'));
                $this->hierarchy($org,$plan,$profile,$campaign,$adset,$ad,false,$ctx['countries']);
                $this->plans->deploymentContext($actor,$id);
                $state='PAUSED';
            }elseif($operation==='ACTIVATE'){
                $campaign=$this->confirmed($org,$id,'CREATE_CAMPAIGN');$adset=$this->confirmed($org,$id,'CREATE_ADSET');$ad=$this->confirmed($org,$id,'CREATE_AD');
                $this->hierarchy($org,$plan,$profile,$campaign,$adset,$ad,false,$ctx['countries']);
                $funding=$this->plans->funding($actor,$id,$p['envelope_id'],$p['reservation_id']);
                if(!$funding['funding_observed']||$funding['finance_revision']!==$p['finance_revision'])throw new DomainException('Pinned Finance reservation not current.');
                $claim=$this->finance->claim($action,[...$plan,...$funding]);
                if(($claim['fresh']??null)!==true)throw new DomainException('Finance exposure already consumed.');
                // Parent stays paused during child activation. No retries, no amount mutation.
                $this->stage($org,$id,$action,'ACTIVATE_AD',fn()=>$this->set($org,$account,$ref,'ad',$ad,'ACTIVE'),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'ad',$remote,'ACTIVE'));
                $this->stage($org,$id,$action,'ACTIVATE_ADSET',fn()=>$this->set($org,$account,$ref,'adset',$adset,'ACTIVE'),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'adset',$remote,'ACTIVE'));
                $this->hierarchy($org,$plan,$profile,$campaign,$adset,$ad,true,$ctx['countries']);
                $this->plans->deploymentContext($actor,$id);
                $this->finance->assertActive($action);
                $this->stage($org,$id,$action,'ACTIVATE_CAMPAIGN',fn()=>$this->set($org,$account,$ref,'campaign',$campaign,'ACTIVE'),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'campaign',$remote,'ACTIVE'));
                $state='ACTIVE_CONFIGURED';
            }else{
                $campaign=$this->confirmed($org,$id,'CREATE_CAMPAIGN');
                $this->stage($org,$id,$action,'PAUSE_CAMPAIGN',fn()=>$this->set($org,$account,$ref,'campaign',$campaign,'PAUSED'),
                    fn(string $remote)=>$this->verify($org,$account,$ref,'campaign',$remote,'PAUSED'));
                $state='PAUSED';
            }
            $receipt=['plan_id'=>$id,'operation'=>$operation,'state'=>$state,'provider_campaign_id'=>$campaign,'spend_confirmed'=>false];
            $this->journal->identify($org,$id,$operation,$action->id,$campaign);
            $this->journal->finish($org,$id,$operation,$action->id,$receipt);
            return $receipt;
        }catch(Throwable $e){
            // Exposure remains held; uncertain commands must not be retried or treated as paused.
            $this->journal->finish($org,$id,$operation,$action->id,null);
            throw new DomainException('Meta command uncertain or blocked after admission; reconcile native journal and Ads Manager.');
        }
    }

    public static function commandHash(string $type,array $parameters):string
    {
        ksort($parameters);
        return hash('sha256',json_encode([$type,$parameters],JSON_THROW_ON_ERROR));
    }

    private function stage(string $org,string $plan,Action $action,string $op,callable $write,callable $read):string
    {
        foreach(MarketingPaidLifecyclePolicy::prerequisites($op) as $prerequisite)$this->confirmed($org,$plan,$prerequisite);
        $receipt=$this->journal->begin($org,$plan,$op,$action->id,hash('sha256',$action->id.':'.$op));
        if(!$receipt['fresh'])throw new DomainException('Provider step already claimed.');
        try{
            $remote=$write();
            $this->journal->identify($org,$plan,$op,$action->id,$remote);
            $evidence=$read($remote);
            $this->journal->finish($org,$plan,$op,$action->id,$evidence);
            return $remote;
        }catch(Throwable $e){$this->journal->finish($org,$plan,$op,$action->id,null);throw $e;}
    }

    private function confirmed(string $org,string $plan,string $op):string
    {
        $row=$this->journal->get($org,$plan,$op);
        if($row===null||$row['state']!=='CONFIRMED'||!is_string($row['provider_id']))throw new DomainException('Verified predecessor receipt required.');
        return $row['provider_id'];
    }
    private function verify(string $org,string $account,string $ref,string $kind,string $id,string $status):array
    {
        $row=$this->meta->read($org,$account,$ref,$kind,$id);
        if($row['configured_status']!==$status)throw new DomainException('Provider configured status mismatch.');
        return ['provider_id'=>$id,'configured_status'=>$status,'account_id'=>$row['account_id']];
    }
    private function set(string $org,string $account,string $ref,string $kind,string $id,string $status):string
    {
        $this->meta->status($org,$account,$ref,$kind,$id,$status);return $id;
    }
    private function hierarchy(string $org,array $plan,array $profile,string $campaign,string $adset,string $ad,bool $active,array $countries):void
    {
        $account=$profile['provider_account_id'];$ref=$profile['credential_ref'];
        if($this->meta->children($org,$account,$ref,$campaign,'adsets')!==[$adset]
            ||$this->meta->children($org,$account,$ref,$campaign,'ads')!==[$ad]
            ||$this->meta->children($org,$account,$ref,$adset,'ads')!==[$ad])throw new DomainException('Unexpected provider hierarchy members.');
        $adsetRead=$this->meta->read($org,$account,$ref,'adset',$adset);
        MarketingPaidLifecyclePolicy::assertDeliveryScope($adsetRead,$countries,$profile['special_ad_categories']!==[]);
        MarketingPaidLifecyclePolicy::assertPausedHierarchy($plan,$account,$campaign,$adset,$ad,$profile['creative_id'],
            $this->meta->read($org,$account,$ref,'campaign',$campaign),$adsetRead,
            $this->meta->read($org,$account,$ref,'ad',$ad),$active,$profile['special_ad_categories'],$profile['special_ad_category_country']);
    }
}
