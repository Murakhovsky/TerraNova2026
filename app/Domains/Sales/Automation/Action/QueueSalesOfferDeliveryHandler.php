<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Action;
use Domains\Sales\Application\Service\SalesOfferDeliveryService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
final readonly class QueueSalesOfferDeliveryHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='sales.offer.delivery.queue';
    public function __construct(private SalesOfferDeliveryService $delivery){}
    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        if(!$this->supports($action->type))return ExecutionResult::failure('Unsupported offer delivery action.');
        try{
            $out=$this->delivery->queue($action);
            if(!in_array($out['state'],['queued','processing','handed_off'],true))return ExecutionResult::failure('Transport handoff requires reconciliation.',$out);
            return ExecutionResult::success($out,['offer_queue_evidence'=>1]);
        }catch(\DomainException $e){return ExecutionResult::failure($e->getMessage());}
    }
}
