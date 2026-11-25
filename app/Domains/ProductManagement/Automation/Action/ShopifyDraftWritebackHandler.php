<?php
declare(strict_types=1);
namespace Domains\ProductManagement\Automation\Action;

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyDraftWritebackService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

final readonly class ShopifyDraftWritebackHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='product_management.shopify.draft.writeback';
    public function __construct(private ShopifyDraftWritebackService $writer,private ActiveModuleResolver $modules) {}
    public function supports(string $type):bool{return $type===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        $p=$action->parameters;
        if(!$this->supports($action->type)||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->executionMode!=='APPROVAL_REQUIRED'
            ||$action->riskLevel!=='HIGH'||!is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
            ||$action->targetType!=='shopify_draft_writeback'||!is_string($action->targetId)||!preg_match('/^[a-f0-9]{32}$/D',$action->targetId)
            ||count($p)!==2||!is_string($p['connection_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['connection_id'])
            ||!is_string($p['payload_digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['payload_digest'])
            ||!$this->modules->isEnabled($action->organizationId,'product_management'))
            return ExecutionResult::failure('Independently approved pinned Shopify Action required.');
        try{
            return ExecutionResult::success($this->writer->apply($action->organizationId,$p['connection_id'],$action->targetId,$p['payload_digest'],$action->idempotencyKey));
        }catch(Throwable){
            return ExecutionResult::failure('Shopify draft writeback blocked or uncertain; inspect native claim and remote readback. No automatic retry.');
        }
    }
}
