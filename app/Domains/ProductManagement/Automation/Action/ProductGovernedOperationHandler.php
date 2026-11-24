<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Automation\Action;

use Domains\ProductManagement\Application\Service\ProductManagementService;
use Domains\ProductManagement\Application\Service\PricingService;
use DomainException;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Kernel\Module\CanonicalCapabilityCatalog;
use Platform\Orchestration\Goal\CapabilityJsonInputValidator;
use Throwable;

/** Closed set of approved, single-native-transaction mutations. No generic tool execution. */
final readonly class ProductGovernedOperationHandler implements IdempotentExternalActionHandlerInterface
{
    public const PREFIX='product_management.governed.';
    public const OPERATIONS=[
        'product.create'=>'product.create',
        'variant.matrix.create'=>'variant.matrix.create',
        'attribute.product.bulk_set'=>'attribute.product.bulk_set',
        'price.base.set'=>'price.set',
        'stock.receive'=>'stock.movement',
        'stock.issue'=>'stock.movement',
        'stock.reserve'=>'stock.reserve',
        'stock.release'=>'stock.release',
        'category.create'=>'category.create',
        'category.assign'=>'category.assign',
        'translation.upsert'=>'product.translation.upsert',
        'variant.create'=>'variant.create',
        'location.create'=>'location.create',
        'product.update'=>'product.update',
        'product.archive'=>'product.archive',
        'variant.update'=>'variant.update',
        'variant.archive'=>'variant.archive',
        'stock.transfer'=>'stock.transfer',
        'price.list.entry.set'=>'price.list.entry.set',
    ];
    public function __construct(private ProductManagementService $products,private PricingService $prices,
        private ActiveModuleResolver $modules,private CanonicalCapabilityCatalog $catalog){}
    public static function types():array{return array_map(static fn($op)=>self::PREFIX.$op,array_keys(self::OPERATIONS));}
    public function supports(string $actionType):bool{return in_array($actionType,self::types(),true);}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        try{
            if(!$this->supports($action->type)||!$this->modules->isEnabled($action->organizationId,'product_management')
                ||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'||!ctype_digit($action->sourceId)||(int)$action->sourceId<1
                ||$action->executionMode!=='APPROVAL_REQUIRED'||$action->riskLevel!=='HIGH'
                ||$action->targetType!=='product_governed_operation'||!is_string($action->targetId)
                ||!is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
                ||$action->correlationId==='')throw new DomainException('Approved canonical Product operation required.');
            $contract=$this->catalog->describe($action->type);
            if($contract===null||$contract->executionBinding!=='action:'.$action->type||$contract->approvalPolicy!=='required')
                throw new DomainException('Governed capability contract absent.');
            (new CapabilityJsonInputValidator())->validate($contract,['target_type'=>$action->targetType,'target_id'=>$action->targetId,'parameters'=>$action->parameters]);
            $op=substr($action->type,strlen(self::PREFIX));$p=$action->parameters;
            $org=$action->organizationId;$key=$action->idempotencyKey;$actor=$action->sourceId;$trace=$action->correlationId;
            $result=match($op){
                'product.create'=>$this->products->createProduct($org,$p,$key,$actor,$trace),
                'variant.matrix.create'=>$this->products->createMatrix($org,$p['product_id'],$p['axes'],$p['sku_prefix'],$p['expected_revision'],$p['uom']??'pcs',$p['track_inventory']??true,$key,$actor,$trace),
                'attribute.product.bulk_set'=>$this->products->bulkSetAttributes($org,$p['products'],$p['values'],$key,$actor,$trace),
                'price.base.set'=>$this->prices->set($org,$p['variant_id'],$p['currency'],$p['amount'],$key,$actor,$trace,$p['expected_revision']),
                'stock.receive','stock.issue'=>$this->products->recordMovement($org,$p['variant_id'],$p['location_id'],($op==='stock.issue'?'-':'').$p['quantity'],$op==='stock.issue'?'issue':'receipt',$p['source_ref'],$key,$actor,$trace),
                'stock.reserve'=>$this->products->reserveStock($org,$p['variant_id'],$p['location_id'],$p['quantity'],$p['source_ref'],$key,$actor,$trace),
                'stock.release'=>$this->products->releaseStock($org,$p['reservation_id'],$p['reason'],$key,$actor,$trace),
                'category.create'=>$this->products->createCategory($org,$p['name'],$p['slug'],$p['parent_id']??null,$key,$actor,$trace),
                'category.assign'=>$this->products->assignCategory($org,$p['product_id'],$p['category_id'],$key,$actor,$trace),
                'translation.upsert'=>$this->products->upsertProductTranslation($org,$p['product_id'],$p['locale'],$p['name'],$p['description']??null,$key,$actor,$trace),
                'variant.create'=>$this->products->createVariant($org,$p['product_id'],$p['sku'],$p['uom']??'pcs',$p['track_inventory']??true,$key,$actor,$trace),
                'location.create'=>$this->products->createLocation($org,$p['code'],$p['name'],$key,$actor,$trace),
                'product.update'=>$this->products->updateProduct($org,$p['product_id'],$p,$p['expected_revision'],$key,$actor,$trace),
                'product.archive'=>$this->products->archiveProduct($org,$p['product_id'],$p['expected_revision'],$key,$actor,$trace),
                'variant.update'=>$this->products->updateVariant($org,$p['variant_id'],$p,$p['expected_revision'],$key,$actor,$trace),
                'variant.archive'=>$this->products->archiveVariant($org,$p['variant_id'],$p['expected_revision'],$key,$actor,$trace),
                'stock.transfer'=>$this->products->transferStock($org,$p['variant_id'],$p['from_location_id'],$p['to_location_id'],$p['quantity'],$p['source_ref'],$key,$actor,$trace),
                'price.list.entry.set'=>$this->prices->setListPrice($org,$p['list_id'],$p['variant_id'],$p['amount'],$p['expected_revision'],$key,$actor,$trace),
            };
            return ExecutionResult::success($result);
        }catch(Throwable){
            return ExecutionResult::failure('Product operation rejected or uncertain. Inspect pinned Run and native receipt; never automatically retry.');
        }
    }
}
