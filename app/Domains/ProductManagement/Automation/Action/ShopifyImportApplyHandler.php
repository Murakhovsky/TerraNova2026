<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Automation\Action;

use Domains\ProductManagement\Infrastructure\Shopify\ShopifyImportApplyService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

/** One domain-owned handler; admission and Approval belong to Kernel Worker. */
final readonly class ShopifyImportApplyHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='product_management.shopify.import.apply';

    public function __construct(
        private ShopifyImportApplyService $imports,
        private ActiveModuleResolver $modules,
    ) {}

    public function supports(string $actionType):bool{return $actionType===self::TYPE;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        if($action->type!==self::TYPE
            ||$action->status!==ActionStatus::Running
            ||$action->sourceType!=='USER'
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1
            ||$action->executionMode!=='APPROVAL_REQUIRED'
            ||$action->riskLevel!=='HIGH'
            ||!is_string($action->idempotencyKey)
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
            ||$action->targetType!=='shopify_import_preview'
            ||!is_string($action->targetId)
            ||!preg_match('/^[a-f0-9]{32}$/D',$action->targetId)
            ||count($action->parameters)!==2
            ||!is_string($action->parameters['source_hash']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$action->parameters['source_hash'])
            ||!is_string($action->parameters['normalized_digest']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$action->parameters['normalized_digest'])) {
            return ExecutionResult::failure('Shopify import requires a running independently approved pinned Workflow Action.');
        }
        if(!$this->modules->isEnabled($action->organizationId,'product_management')) {
            return ExecutionResult::failure('Product Management inactive for tenant.');
        }
        try {
            $result=$this->imports->applyApproved(
                $action->organizationId,$action->targetId,
                $action->parameters['source_hash'],
                $action->parameters['normalized_digest'],
                $action->sourceId,$this->idempotencyKey($action),
                $action->correlationId!==''?$action->correlationId:$action->id
            );
            return ExecutionResult::success($result);
        }catch(Throwable) {
            // Native effects may have committed before a failure. No blind retry.
            return ExecutionResult::failure('Approved Shopify import blocked: verify native receipts and reconcile manually.');
        }
    }
}
