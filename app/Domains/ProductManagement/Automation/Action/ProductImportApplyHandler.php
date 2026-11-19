<?php
declare(strict_types=1);

namespace Domains\ProductManagement\Automation\Action;

use Domains\ProductManagement\Application\Service\CsvProductImportService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Kernel\Module\ActiveModuleResolver;
use Throwable;

/**
 * Sole E3 CSV applying entry point, owned by the canonical Action Worker.
 * Never dispatches a second executor, starts an unapproved Plan or trusts
 * approval text passed from the UI/AI/CRON.
 */
final readonly class ProductImportApplyHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='product_management.import.apply';

    public function __construct(private CsvProductImportService $imports,private ActiveModuleResolver $modules) {}

    public function supports(string $actionType):bool { return $actionType===self::TYPE; }

    public function idempotencyKey(Action $action):string {
        return ExternalActionIdempotency::resolve($action);
    }

    public function execute(Action $action):ExecutionResult
    {
        if($action->type!==self::TYPE
            ||$action->status!==ActionStatus::Running
            ||$action->sourceType!=='USER'||!ctype_digit($action->sourceId)
            ||(int)$action->sourceId<1
            ||$action->executionMode!=='APPROVAL_REQUIRED'
            ||$action->riskLevel!=='HIGH'
            ||!is_string($action->idempotencyKey)
            ||!preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
            ||$action->targetType!=='product_import_batch'
            ||!is_string($action->targetId)
            ||!preg_match('/^[a-f0-9]{32}$/D',$action->targetId)
            ||count($action->parameters)!==2
            ||!is_string($action->parameters['batch_digest']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$action->parameters['batch_digest'])
            ||!is_string($action->parameters['rows_digest']??null)
            ||!preg_match('/^[a-f0-9]{64}$/D',$action->parameters['rows_digest'])) {
            return ExecutionResult::failure('Product import requires a running independently approved pinned Workflow Action.');
        }
        if(!$this->modules->isEnabled($action->organizationId,'product_management')) {
            return ExecutionResult::failure('Product Management module is inactive for tenant.');
        }
        try {
            $data=$this->imports->applyApproved(
                $action->organizationId,$action->targetId,
                $action->parameters['batch_digest'],$action->parameters['rows_digest'],
                $action->sourceId,$this->idempotencyKey($action),
                $action->correlationId!==''?$action->correlationId:$action->id
            );
            return ExecutionResult::success($data);
        }catch(Throwable $e){
            // Deliberately no raw CSV, product name, provider credentials or
            // mutable approval text in worker receipts or diagnostics.
            return ExecutionResult::failure('Approved Product CSV requires manual reconciliation of native receipts.');
        }
    }
}
