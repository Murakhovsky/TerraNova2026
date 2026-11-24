<?php
declare(strict_types=1);
namespace Domains\Marketing\Application\Service;

use DomainException;
use Domains\Marketing\Domain\MarketingPaidLifecyclePolicy;
use Domains\Marketing\Infrastructure\Meta\MetaPaidExecutionProfiles;
use Domains\Marketing\Infrastructure\Persistence\MySql\MysqlMarketingPaidOperationJournal;
use Kernel\Tenant\Model\TenantContext;

/** Native receipt readback, not a live provider probe or financial settlement. */
final readonly class MarketingPaidOperationsReadService
{
    public function __construct(private MarketingPaidLaunchPlanService $plans,private MysqlMarketingPaidOperationJournal $journal,
        private MetaPaidExecutionProfiles $profiles,private MarketingPaidReadService $paid){}
    public function status(TenantContext $actor,string $id):array
    {
        $plan=$this->plans->get($actor,$id);$org=$actor->organizationId()->value();
        $operations=[];$blocked=false;
        foreach(MysqlMarketingPaidOperationJournal::OPERATIONS as $op){
            $row=$this->journal->get($org,$id,$op);if($row===null)continue;
            $operations[]=['operation'=>$op,'state'=>$row['state'],'provider_id'=>$row['provider_id'],'action_id'=>$row['action_id']];
            if($row['state']!=='CONFIRMED')$blocked=true;
        }
        $ready=$this->profiles->readiness($org,$plan);
        return ['plan_id'=>$id,'plan_hash'=>$plan['plan_hash'],...$ready,'operations'=>$operations,
            'manual_reconciliation_required'=>$blocked,'live_verified'=>false,'finance_settled'=>false];
    }
    /** Internal owner readback of immutable paid lifecycle and latest source revision.
     * A terminal COS journal is not an invoice or live Ads Manager proof.
     */
    public function settlementSource(TenantContext $actor,string $id,string $snapshotId):array
    {
        $plan=$this->plans->get($actor,$id);$org=$actor->organizationId()->value();
        $rows=[];
        foreach(MysqlMarketingPaidOperationJournal::OPERATIONS as $op){
            $row=$this->journal->get($org,$id,$op);
            if($row===null||($row['state']??null)!=='CONFIRMED'||!is_array($row['receipt']??null)
                ||!is_string($row['provider_id']??null)||!preg_match('/^[0-9]{1,30}$/D',$row['provider_id'])
                ||!is_string($row['action_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$row['action_id'])
                ||!is_string($row['completed_at']??null)){
                throw new DomainException('All paid lifecycle operations must have confirmed native receipts.');
            }
            $rows[$op]=$row;
        }
        $campaign=$rows['CREATE_CAMPAIGN']['provider_id'];
        $groups=['DEPLOY_PAUSED'=>['CREATE_CAMPAIGN','CREATE_ADSET','CREATE_AD'],
            'ACTIVATE'=>['ACTIVATE_AD','ACTIVATE_ADSET','ACTIVATE_CAMPAIGN'],'PAUSE'=>['PAUSE_CAMPAIGN']];
        foreach($groups as $command=>$steps){
            $parent=$rows[$command];$proof=$parent['receipt'];
            if($parent['provider_id']!==$campaign||($proof['plan_id']??null)!==$id
                ||($proof['operation']??null)!==$command||($proof['provider_campaign_id']??null)!==$campaign
                ||($proof['state']??null)!==($command==='ACTIVATE'?'ACTIVE_CONFIGURED':'PAUSED')){
                throw new DomainException('Paid command receipt does not bind the native campaign.');
            }
            foreach($steps as $step){
                $row=$rows[$step];$proof=$row['receipt'];
                if($row['action_id']!==$parent['action_id']||($proof['provider_id']??null)!==$row['provider_id']
                    ||($proof['configured_status']??null)!==($command==='ACTIVATE'?'ACTIVE':'PAUSED')){
                    throw new DomainException('Paid operation receipt does not bind its approved command.');
                }
            }
        }
        foreach(['ACTIVATE_CAMPAIGN','PAUSE_CAMPAIGN'] as $step)
            if($rows[$step]['provider_id']!==$campaign)throw new DomainException('Terminal campaign identity differs.');
        foreach(['ADSET','AD'] as $kind)
            if($rows['ACTIVATE_'.$kind]['provider_id']!==$rows['CREATE_'.$kind]['provider_id'])throw new DomainException('Paid child identity differs.');
        if(count(array_unique(array_column(array_intersect_key($rows,$groups),'action_id')))!==3)
            throw new DomainException('Distinct approved paid commands required.');
        $snapshot=$this->paid->spendEvidence($actor,$plan['account_id'],$snapshotId);
        MarketingPaidLifecyclePolicy::spendComparison($plan,$campaign,$snapshot);
        return ['plan'=>['organization_id'=>$org,...array_intersect_key($plan,array_flip(['id','plan_hash','currency','lifetime_minor','account_timezone','account_id','period_start','period_end']))],
            'terminal'=>['organization_id'=>$org,'plan_id'=>$id,'activation_action_id'=>$rows['ACTIVATE']['action_id'],
                'state'=>'PAUSED_CONFIRMED','provider_campaign_id'=>$campaign,'pause_action_id'=>$rows['PAUSE']['action_id'],'unresolved_operations'=>[]],
            'observation'=>['organization_id'=>$org,...$snapshot]];
    }

    public function compareSpend(TenantContext $actor,string $id,string $snapshotId):array
    {
        $plan=$this->plans->get($actor,$id);$org=$actor->organizationId()->value();
        $deployed=$this->journal->get($org,$id,'DEPLOY_PAUSED');
        $campaign=$this->journal->get($org,$id,'CREATE_CAMPAIGN');
        if(($deployed['state']??null)!=='CONFIRMED'||($campaign['state']??null)!=='CONFIRMED'
            ||!is_string($campaign['provider_id']??null)||$deployed['provider_id']!==$campaign['provider_id']){
            throw new DomainException('Confirmed native campaign binding required for spend comparison.');
        }
        $snapshot=$this->paid->spendEvidence($actor,$plan['account_id'],$snapshotId);
        return ['plan_id'=>$id,...MarketingPaidLifecyclePolicy::spendComparison($plan,$campaign['provider_id'],$snapshot)];
    }
}
