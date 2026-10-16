<?php
declare(strict_types=1);

namespace Domains\Property\Automation\Action;

use App\Persistence\Property\PropertyBulkIntakeService;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/** Runs only as canonical, independently approved Kernel Action worker. */
final readonly class PropertyBulkCreateHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE='property.bulk.create';

    public function __construct(private PropertyBulkIntakeService $intake) {}

    public function supports(string $actionType):bool { return $actionType===self::TYPE; }
    public function idempotencyKey(Action $action):string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action):ExecutionResult
    {
        if ($action->status!==ActionStatus::Running
            || $action->sourceType!=='USER' || !ctype_digit($action->sourceId)
            || (int)$action->sourceId<1
            || $action->executionMode!=='APPROVAL_REQUIRED'
            || $action->riskLevel!=='HIGH'
            // A manually submitted Action, even if independently approved,
            // must not skip the approved Federation Plan and Workflow claim.
            || !is_string($action->idempotencyKey)
            || !preg_match('/^wf:[a-f0-9]{64}$/D',$action->idempotencyKey)
            || $action->targetType!=='property_bulk_batch'
            || !is_string($action->targetId)
            || !preg_match('/^bulk-[a-f0-9]{24}$/D',$action->targetId)
            || count($action->parameters)!==1
            || !is_string($action->parameters['batch_digest']??null)
            || !preg_match('/^[a-f0-9]{64}$/D',$action->parameters['batch_digest'])) {
            return ExecutionResult::failure('Property bulk needs a running approved USER Action and immutable batch.');
        }
        try {
            return ExecutionResult::success($this->intake->executeApproved(
                $action->organizationId,$action->targetId,
                $action->parameters['batch_digest'],
                (int)$action->sourceId,$this->idempotencyKey($action),
                $action->correlationId!==''?$action->correlationId:$action->id,
            ));
        } catch(Throwable $e) {
            return ExecutionResult::failure('Property bulk action requires manual reconciliation: '.$e->getMessage());
        }
    }
}
