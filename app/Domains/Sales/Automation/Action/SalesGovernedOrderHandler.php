<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Action;

use DomainException;
use Domains\Sales\Application\Contract\SalesOrderActionAuthority;
use Domains\Sales\Application\Service\SalesMutationGuard;
use Domains\Sales\Application\Service\SalesOrderService;
use Domains\Sales\Application\Service\SalesOrderLifecycleService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Throwable;

/** Approved transport over existing native order services; never a second executor. */
final readonly class SalesGovernedOrderHandler implements IdempotentExternalActionHandlerInterface
{
    public const PREFIX='sales.governed.order.';
    private const OPS=['create','lifecycle.confirm','lifecycle.allocate','lifecycle.release','lifecycle.fulfill','lifecycle.cancel','lifecycle.return'];
    public function __construct(private SalesOrderService $orders,private SalesOrderLifecycleService $lifecycle,
        private SalesMutationGuard $guard,private SalesOrderActionAuthority $approval,
        private ActiveModuleResolver $modules,private CanonicalCapabilityCatalog $catalog){}
    public static function types():array{return array_map(static fn(string $op):string=>self::PREFIX.$op,self::OPS);}
    public function supports(string $actionType):bool{return in_array($actionType,self::types(),true);}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        try{
            if(!$this->supports($action->type)||!$this->modules->isEnabled($action->organizationId,'sales')||
                $action->status!==ActionStatus::Running||$action->sourceType!=='USER'||!ctype_digit($action->sourceId)||
                (int)$action->sourceId<1||(string)(int)$action->sourceId!==$action->sourceId||
                $action->executionMode!=='APPROVAL_REQUIRED'||$action->riskLevel!=='HIGH'||
                !is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)||
                !is_string($action->targetId)||!preg_match('/^[a-f0-9]{32}$/D',$action->targetId)||$action->correlationId==='')
                throw new DomainException('Approved canonical Sales Order Action required.');
            $op=substr($action->type,strlen(self::PREFIX));$p=$action->parameters;
            if($op==='create'?$action->targetType!=='sales_order_intent':
                ($action->targetType!=='sales_order'||($p['order_id']??null)!==$action->targetId))
                throw new DomainException('Sales Order target mismatch.');
            $contract=$this->catalog->describe($action->type);
            if($contract===null||$contract->executionBinding!=='action:'.$action->type||
                $contract->approvalPolicy!=='required'||$contract->sideEffectLevel!=='internal')
                throw new DomainException('Sales governed capability unavailable.');
            (new CapabilityJsonInputValidator())->validate($contract,['target_type'=>$action->targetType,'target_id'=>$action->targetId,'parameters'=>$p]);
            $org=$action->organizationId;$actor=(int)$action->sourceId;$key=$this->idempotencyKey($action);
            $result=$this->guard->run($org,$actor,function()use($action,$org,$actor,$op,$p,$key):array{
                $this->approval->assertApprovedOrderExecution($action);
                return $op==='create'?$this->orders->create($org,$actor,$p,$key,$action->correlationId):
                    $this->lifecycle->execute($org,$actor,substr($op,strlen('lifecycle.')),$p,$key,$action->correlationId);
            });
            return ExecutionResult::success($result);
        }catch(Throwable){
            return ExecutionResult::failure('Sales Order operation rejected or uncertain; inspect canonical Run and native receipt before any retry.');
        }
    }
}
