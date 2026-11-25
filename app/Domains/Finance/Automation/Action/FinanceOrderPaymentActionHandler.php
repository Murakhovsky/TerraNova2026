<?php
declare(strict_types=1);
namespace Domains\Finance\Automation\Action;
use Domains\Finance\Infrastructure\Persistence\MySql\MysqlCommerceOrderPaymentStore;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

final readonly class FinanceOrderPaymentActionHandler implements IdempotentExternalActionHandlerInterface
{
    public const REGISTER='finance.order.payment.register';
    public const RECONCILE='finance.order.payment.reconcile';
    public function __construct(private MysqlCommerceOrderPaymentStore $payments){}
    public function supports(string $type):bool{return in_array($type,[self::REGISTER,self::RECONCILE],true);}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        if(!$this->supports($action->type)||$action->targetType!=='sales_order'||!is_string($action->targetId)||!ctype_digit($action->sourceId)||(int)$action->sourceId<1)return ExecutionResult::failure('Typed order and numeric requesting actor required.');
        try{
            $p=$action->parameters;
            if(($p['order_id']??null)!==$action->targetId)throw new \DomainException('Order target mismatch.');
            $args=[$action->organizationId,$action->targetId,$p,$action->sourceId,$action->id,$action->correlationId!==''?$action->correlationId:$action->id,$this->idempotencyKey($action)];
            return ExecutionResult::success($action->type===self::REGISTER?$this->payments->register(...$args):$this->payments->reconcile(...$args));
        }catch(Throwable $e){
            error_log('finance.order.payment.blocked '.$e::class);
            return ExecutionResult::failure('Finance order operation blocked; inspect receipt and statement before retry.');
        }
    }
}
