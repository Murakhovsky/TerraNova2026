<?php
declare(strict_types=1);
namespace Domains\Finance\Application\Service;

use DomainException;
use Domains\Finance\Application\Contract\FinancePaidExposureClaimStore;
use Domains\Finance\Domain\BudgetControlPolicy;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Module\ActiveModuleResolver;

/** Only the approved canonical activation worker can claim exposure. No direct Capability adapter. */
final readonly class ApprovedFinancePaidExposureService
{
    public function __construct(private FinancePaidExposureClaimStore $claims,private WorkflowActionWorkerAdmissionInterface $admission,
        private ActiveModuleResolver $modules,private BudgetControlPolicy $policy){}

    public function claim(Action $action,array $funding):array
    {
        if($action->type!=='marketing.paid.meta.campaign.activate'||$action->status!==ActionStatus::Running
            ||$action->targetType!=='marketing_paid_launch_plan'||$action->sourceType!=='USER'
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1
            ||$action->executionMode!=='APPROVAL_REQUIRED'||$action->riskLevel!=='HIGH'
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',(string)$action->idempotencyKey)
            ||!$this->modules->isEnabled($action->organizationId,'finance')){
            throw new DomainException('Approved canonical paid activation required.');
        }
        $this->admission->assertAuthorized($action);
        $p=$action->parameters;
        foreach(['envelope_id','reservation_id','plan_hash','currency','period_start','period_end','lifetime_minor','finance_revision'] as $key){
            if(!array_key_exists($key,$funding))throw new DomainException('Incomplete Finance exposure binding.');
        }
        foreach(['envelope_id','reservation_id','plan_hash','finance_revision'] as $key){
            if(($p[$key]??null)!==$funding[$key])throw new DomainException('Finance binding differs from approved Action.');
        }
        if(!is_string($action->targetId)||!preg_match('/^mkpl-[a-f0-9]{24}$/D',$action->targetId)
            ||($funding['plan_id']??null)!==$action->targetId||!is_string($funding['plan_hash'])
            ||!preg_match('/^[a-f0-9]{64}$/D',$funding['plan_hash'])
            ||!is_string($funding['currency'])||!preg_match('/^[A-Z]{3}$/D',$funding['currency'])){
            throw new DomainException('Invalid Finance exposure source.');
        }
        $start=$this->policy->date($funding['period_start']);$end=$this->policy->date($funding['period_end']);
        if($start>$end||$start->diff($end)->days>30)throw new DomainException('Invalid exposure period.');
        return $this->claims->claimExposure($action->organizationId,BudgetControlPolicy::id($funding['envelope_id']),
            BudgetControlPolicy::id($funding['reservation_id'],'finr-'),$action->sourceId,$action->id,$action->correlationId,
            ['source_ref'=>$action->targetId,'plan_hash'=>$funding['plan_hash'],'currency'=>$funding['currency'],
                'period_start'=>$start->format('Y-m-d'),'period_end'=>$end->format('Y-m-d'),
                'amount_minor'=>BudgetControlPolicy::amount($funding['lifetime_minor']),
                'expected_revision'=>BudgetControlPolicy::revision($funding['finance_revision']),
                'source_operation_key'=>'mkt-paid:'.$action->targetId.':activate']);
    }
}
