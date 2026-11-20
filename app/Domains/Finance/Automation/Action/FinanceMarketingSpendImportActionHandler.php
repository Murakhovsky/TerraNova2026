<?php
declare(strict_types=1);
namespace Domains\Finance\Automation\Action;

use Domains\Finance\Application\Service\ApprovedFinanceMarketingSpendImportOperations;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/**
 * Action/Policy/Approval controlled readback, never direct Meta API writes.
 * A request must pin the immutable reviewed source revision and provider hash.
 */
final readonly class FinanceMarketingSpendImportActionHandler implements IdempotentExternalActionHandlerInterface
{
    public const IMPORT='finance.ad_spend.marketing_snapshot.import';

    public function __construct(private ApprovedFinanceMarketingSpendImportOperations $operations){}

    public function supports(string $type):bool{return $type===self::IMPORT;}
    public function idempotencyKey(Action $action):string{return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        $p=$action->parameters;
        $allowed=['ad_account_ref','source_snapshot_ref','expected_source_revision','expected_source_hash'];
        if(!$this->supports($action->type)
            ||$action->targetType!=='marketing_spend_snapshot'
            ||!is_string($action->targetId)
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1
            ||array_diff(array_keys($p),$allowed)!==[]
            ||!is_string($p['source_snapshot_ref']??null)
            ||$p['source_snapshot_ref']!==$action->targetId
            ||!is_string($p['ad_account_ref']??null)
            ||!is_int($p['expected_source_revision']??null)
            ||!is_string($p['expected_source_hash']??null)){
            return ExecutionResult::failure('Finance source import requires canonical typed Action target and approved snapshot revision.');
        }
        try{
            $result=$this->operations->import(
                $action->organizationId,
                $p['ad_account_ref'],$action->targetId,
                $p['expected_source_revision'],$p['expected_source_hash'],
                $this->idempotencyKey($action),
                $action->correlationId!==''?$action->correlationId:$action->id
            );
            return ExecutionResult::success($result);
        }catch(Throwable $error){
            // No provider token, source spend, or raw DB response escapes Action logs.
            error_log('finance.marketing_spend.import_failed '.$error::class);
            return ExecutionResult::failure('Finance evidence Action blocked; inspect source revision and persisted Finance intent before re-approval or retry.');
        }
    }
}
