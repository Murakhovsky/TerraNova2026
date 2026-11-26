<?php
declare(strict_types=1);
namespace Domains\Marketing\Automation\Action;

use DomainException;
use Domains\Marketing\Infrastructure\Hermes\HermesAnalystExecutionFactory;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Service\TenantContextFactory;
use Kernel\Tenant\Model\TenantPermissions;
use Throwable;

/** Exactly one canonical external advisory Action. No direct web or agent execution. */
final readonly class MarketingAnalystRunHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='marketing.analyst.run.request';

    public function __construct(private HermesAnalystExecutionFactory $factory,
        private WorkflowActionWorkerAdmissionInterface $workerAdmission,private IdentityResolverInterface $identities,
        private TenantContextFactory $tenants,private ActiveModuleResolver $modules){}

    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        try{
            if($action->type!==self::TYPE||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
                ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->executionMode!=='APPROVAL_REQUIRED'
                ||$action->riskLevel!=='HIGH'||!is_string($action->idempotencyKey)
                ||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
                ||$action->targetType!=='marketing_campaign'||!is_string($action->targetId)
                ||!preg_match('/^mkt-[a-f0-9]{24}$/D',$action->targetId)
                ||!$this->modules->isEnabled($action->organizationId,'marketing')){
                throw new DomainException('Canonical Marketing Action identity required.');
            }
            $p=$action->parameters;
            $allowed=['campaign_revision','creative_id','creative_revision','configuration_hash','context_hash','adset_id','adset_revision','paid_plan_id','snapshot_ref'];
            if(array_diff(array_keys($p),$allowed)!==[]||!is_int($p['campaign_revision']??null)
                ||!is_string($p['creative_id']??null)||!is_int($p['creative_revision']??null)
                ||!is_string($p['configuration_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['configuration_hash'])
                ||!is_string($p['context_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['context_hash'])
                ||array_key_exists('paid_plan_id',$p)!==array_key_exists('snapshot_ref',$p)
                ||(array_key_exists('paid_plan_id',$p)&&(!is_string($p['paid_plan_id'])||!is_string($p['snapshot_ref'])))
                ||array_key_exists('adset_id',$p)!==array_key_exists('adset_revision',$p)
                ||(array_key_exists('adset_id',$p)&&(!is_string($p['adset_id'])||!is_int($p['adset_revision'])))){
                throw new DomainException('Malformed pinned Marketing Action.');
            }
            // Reuse the persisted first attempt, approved Plan, Policy and independent Approval checks.
            $this->workerAdmission->assertAuthorized($action);
            $identity=$this->identities->resolve(UserId::fromString($action->sourceId),OrganizationId::fromString($action->organizationId));
            if($identity===null||$identity->userId()->value()!==$action->sourceId
                ||$identity->organizationId()->value()!==$action->organizationId)throw new DomainException('Action requester no longer belongs to tenant.');
            $actor=$this->tenants->fromIdentity($identity);
            if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE)){
                throw new DomainException('Action requester no longer authorized.');
            }
            $execution=$this->factory->create($action->organizationId,$p['configuration_hash']);
            $run=$execution->request($actor,$action->targetId,$p['campaign_revision'],$p['creative_id'],$p['creative_revision'],
                $p['adset_id']??null,$p['adset_revision']??null,$this->idempotencyKey($action),$p['context_hash'],$p['paid_plan_id']??null,$p['snapshot_ref']??null);
            if($run['state']!=='SUCCEEDED'||!hash_equals($p['context_hash'],$run['context_hash'])
                ||$run['advice']===null||$run['paid_activation_approved']!==false){
                return ExecutionResult::failure('Marketing advisory turn unresolved; reconcile native receipt before any new request.',
                    ['run_id'=>$run['id'],'state'=>$run['state']]);
            }
            return ExecutionResult::success(['run_id'=>$run['id'],'state'=>'SUCCEEDED','context_hash'=>$run['context_hash'],
                'paid_activation_approved'=>false]);
        }catch(Throwable){
            return ExecutionResult::failure('Marketing advisory Action blocked; inspect approved context and native receipts. No automatic retry.');
        }
    }
}
