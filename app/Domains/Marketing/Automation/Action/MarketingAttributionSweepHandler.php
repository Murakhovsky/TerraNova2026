<?php
declare(strict_types=1);
namespace Domains\Marketing\Automation\Action;
use DomainException;
use Domains\Marketing\Application\Service\MarketingAttributionSweepService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\Contract\WorkflowActionWorkerAdmissionInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Identity\Contract\IdentityResolverInterface;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Kernel\Shared\Domain\OrganizationId;
use Kernel\Shared\Domain\UserId;
use Kernel\Tenant\Service\TenantContextFactory;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Throwable;
final readonly class MarketingAttributionSweepHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='marketing.attribution.sweep';
    public function __construct(private MarketingAttributionSweepService $service,private WorkflowActionWorkerAdmissionInterface $admission,
        private IdentityResolverInterface $identities,private TenantContextFactory $tenants,private ActiveModuleResolver $modules,
        private CanonicalCapabilityCatalog $catalog){}
    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        try{
            if(!$this->supports($action->type)||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
                ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->executionMode!=='APPROVAL_REQUIRED'
                ||$action->riskLevel!=='HIGH'||!is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
                ||$action->targetType!=='marketing_campaign'||!is_string($action->targetId)||!preg_match('/^mkt-[a-f0-9]{24}$/D',$action->targetId)
                ||!$this->modules->isEnabled($action->organizationId,'marketing'))throw new DomainException('Canonical Marketing sweep action required.');
            $contract=$this->catalog->describe(self::TYPE);
            if($contract===null||$contract->executionBinding!=='action:'.self::TYPE||$contract->approvalPolicy!=='required'||$contract->sideEffectLevel!=='internal')
                throw new DomainException('Sweep capability unavailable.');
            $p=$action->parameters;
            (new CapabilityJsonInputValidator())->validate($contract,['target_type'=>$action->targetType,'target_id'=>$action->targetId,'parameters'=>$p]);
            $this->admission->assertAuthorized($action);
            $identity=$this->identities->resolve(UserId::fromString($action->sourceId),OrganizationId::fromString($action->organizationId));
            if($identity===null||$identity->userId()->value()!==$action->sourceId||$identity->organizationId()->value()!==$action->organizationId)
                throw new DomainException('Live tenant identity unavailable.');
            return ExecutionResult::success($this->service->sweep($this->tenants->fromIdentity($identity),$action->targetId,
                $p['plan_id'],$p['source_kind'],$p['window_start'],$p['window_end'],$p['after'],$this->idempotencyKey($action),$p['max_pages']??1));
        }catch(Throwable){
            return ExecutionResult::failure('Marketing attribution sweep rejected or uncertain; inspect canonical action and native capture receipt.');
        }
    }
}
