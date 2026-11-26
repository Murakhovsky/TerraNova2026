<?php
declare(strict_types=1);
namespace Domains\Marketing\Automation\Action;
use DomainException;
use Domains\Marketing\Application\Service\MarketingOptimizationPauseGuard;
use Domains\Marketing\Infrastructure\Meta\ApprovedMetaPaidLifecycle;
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

final readonly class MarketingPaidLifecycleHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPES=['marketing.paid.meta.campaign.create_paused','marketing.paid.meta.campaign.activate','marketing.paid.meta.campaign.pause'];
    public function __construct(private ApprovedMetaPaidLifecycle $lifecycle,private WorkflowActionWorkerAdmissionInterface $admission,
        private IdentityResolverInterface $identities,private TenantContextFactory $tenants,private ActiveModuleResolver $modules,private ?MarketingOptimizationPauseGuard $optimization=null){}
    public function supports(string $type):bool{return in_array($type,self::TYPES,true);}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        try{
            if(!$this->supports($action->type)||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
                ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->executionMode!=='APPROVAL_REQUIRED'||$action->riskLevel!=='HIGH'
                ||!preg_match('/^wf:[a-f0-9]{64}$/D',(string)$action->idempotencyKey)||$action->targetType!=='marketing_paid_launch_plan'
                ||!preg_match('/^mkpl-[a-f0-9]{24}$/D',(string)$action->targetId)||!$this->modules->isEnabled($action->organizationId,'marketing')){
                throw new DomainException('Canonical paid Marketing Action required.');
            }
            $p=$action->parameters;$keys=array_keys($p);sort($keys);
            $activation=$action->type===self::TYPES[1];
            $reviewedPause=$action->type===self::TYPES[2]&&array_key_exists('optimization_review',$p);
            if($keys!==($activation?['configuration_hash','envelope_id','finance_revision','plan_hash','reservation_id']:($reviewedPause?['configuration_hash','optimization_review','plan_hash']:['configuration_hash','plan_hash']))
                ||!is_string($p['configuration_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['configuration_hash'])
                ||!is_string($p['plan_hash']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['plan_hash'])
                ||($activation&&(!is_string($p['envelope_id'])||!preg_match('/^finb-[a-f0-9]{24}$/D',$p['envelope_id'])
                    ||!is_string($p['reservation_id'])||!preg_match('/^finr-[a-f0-9]{24}$/D',$p['reservation_id'])
                    ||!is_int($p['finance_revision'])||$p['finance_revision']<1))){
                throw new DomainException('Invalid approved paid operation parameters.');
            }
            $this->admission->assertAuthorized($action);
            $identity=$this->identities->resolve(UserId::fromString($action->sourceId),OrganizationId::fromString($action->organizationId));
            if($identity===null||$identity->userId()->value()!==$action->sourceId||$identity->organizationId()->value()!==$action->organizationId)
                throw new DomainException('Requester no longer has tenant membership.');
            $actor=$this->tenants->fromIdentity($identity);
            if(!$actor->isManager()||!$actor->allows(TenantPermissions::ACCESS)||!$actor->allows(TenantPermissions::MANAGE))
                throw new DomainException('Requester no longer authorized.');
            if($reviewedPause){
                if($this->optimization===null||!is_array($p['optimization_review']))throw new DomainException('Optimization pause validation unavailable.');
                $this->optimization->verify($actor,(string)$action->targetId,$p['optimization_review']);
            }
            return ExecutionResult::success($this->lifecycle->execute($action,$actor));
        }catch(Throwable){return ExecutionResult::failure('Paid Marketing Action blocked or uncertain. Inspect native journal; never retry blindly.');}
    }
}
