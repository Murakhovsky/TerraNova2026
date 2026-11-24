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
