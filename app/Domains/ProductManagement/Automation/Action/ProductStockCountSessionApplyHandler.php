<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Automation\Action;

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

/** Worker-only writer; independent approval and immutable Plan admission belong to Kernel. */
final readonly class ProductStockCountSessionApplyHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='product_management.stock.count.session.apply';
    public function __construct(private ProductManagementService $products,private ActiveModuleResolver $modules){}
    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        if(!$this->modules->isEnabled($action->organizationId,'product_management'))
            return ExecutionResult::failure('Product Management disabled for tenant.');
        try{
            return ExecutionResult::success($this->products->applyApprovedCountSession($action));
        }catch(Throwable $e){
            return ExecutionResult::failure('Stock count rejected or uncertain; independently inspect native count and ledger before retry.');
        }
    }
}
