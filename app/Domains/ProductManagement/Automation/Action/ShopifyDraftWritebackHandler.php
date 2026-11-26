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
    public const PUBLISH='product_management.shopify.channel.publish';
    public function __construct(private ShopifyDraftWritebackService $writer,private ActiveModuleResolver $modules) {}
    public function supports(string $type):bool{return in_array($type,[self::TYPE,self::PUBLISH],true);}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}
    public function execute(Action $action):ExecutionResult
    {
        $p=$action->parameters;$publish=$action->type===self::PUBLISH;
        if(!$this->supports($action->type)||$action->status!==ActionStatus::Running||$action->sourceType!=='USER'
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1||$action->executionMode!=='APPROVAL_REQUIRED'
            ||$action->riskLevel!=='HIGH'||!is_string($action->idempotencyKey)||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
            ||$action->targetType!==($publish?'shopify_channel_publish':'shopify_draft_writeback')||!is_string($action->targetId)||!preg_match('/^[a-f0-9]{32}$/D',$action->targetId)
            ||array_diff(array_keys($p),($publish?['connection_id','payload_digest','collection_gids','publication_gid','resync_id']:['connection_id','payload_digest','collection_gids','resync_id']))!==[]||!is_string($p['connection_id']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['connection_id'])
            ||!is_string($p['payload_digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$p['payload_digest'])
            ||($publish&&(!is_string($p['publication_gid']??null)||!preg_match('#^gid://shopify/Publication/[0-9]+$#D',$p['publication_gid'])))
            ||(isset($p['resync_id'])&&(!is_string($p['resync_id'])||!preg_match('/^[a-f0-9]{32}$/D',$p['resync_id'])))
            ||(isset($p['collection_gids'])&&!is_array($p['collection_gids']))
            ||!$this->modules->isEnabled($action->organizationId,'product_management'))
            return ExecutionResult::failure('Independently approved pinned Shopify Action required.');
        try{
            return ExecutionResult::success($this->writer->apply($action->organizationId,$p['connection_id'],$action->targetId,$p['payload_digest'],$action->idempotencyKey,$p['collection_gids']??[],$publish?$p['publication_gid']:null,$p['resync_id']??null));
        }catch(Throwable){
            return ExecutionResult::failure('Shopify draft writeback blocked or uncertain; inspect native claim and remote readback. No automatic retry.');
        }
    }
}
