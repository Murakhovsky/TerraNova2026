<?php
declare(strict_types=1);
namespace Domains\Finance\Automation\Action;

use Domains\Finance\Application\Service\ApprovedFinanceBudgetOperations;
use Kernel\Action\Action;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/** Canonical worker only, independent Finance Approval required. */
final readonly class FinanceBudgetActionHandler implements IdempotentExternalActionHandlerInterface
{
    public const ACTIVATE='finance.budget.envelope.activate';
    public const RESERVE='finance.budget.reservation.reserve';
    public const RELEASE='finance.budget.reservation.release';
    public const FREEZE='finance.budget.envelope.freeze';

    public function __construct(private ApprovedFinanceBudgetOperations $budgets){}

    public function supports(string $type):bool
    {
        return in_array($type,[self::ACTIVATE,self::RESERVE,self::RELEASE,self::FREEZE],true);
    }
    public function idempotencyKey(Action $action):string {return ExternalActionIdempotency::resolve($action);}

    public function execute(Action $action):ExecutionResult
    {
        if(!$this->supports($action->type)
            ||!is_string($action->targetId)||$action->targetId===''
            ||!ctype_digit($action->sourceId)||(int)$action->sourceId<1
            ||($action->type===self::RELEASE && $action->targetType!=='finance_reservation')
            ||($action->type!==self::RELEASE && $action->targetType!=='finance_budget')){
            return ExecutionResult::failure('Finance Action requires a matching typed target and a numeric requesting actor.');
        }
        try{
            $org=$action->organizationId;
            $id=$action->targetId;
            $actor=$action->sourceId;
            $trace=$action->correlationId!==''?$action->correlationId:$action->id;
            $key=$this->idempotencyKey($action);
            $p=$action->parameters;
            $result=match($action->type){
                self::ACTIVATE=>$this->budgets->activate(
                    $org,$id,$actor,$action->id,$trace,$p['expected_revision']??null,$key
                ),
                self::RESERVE=>$this->budgets->reserve(
                    $org,$id,$actor,$action->id,$trace,
                    $p['amount_minor']??null,$p['expected_revision']??null,
                    $p['source_domain']??null,$p['source_operation_key']??null,$p['reason']??null,$key
                ),
                self::RELEASE=>$this->budgets->release(
                    $org,$id,$actor,$action->id,$trace,$p['reason']??null,$key
                ),
                self::FREEZE=>$this->budgets->freeze(
                    $org,$id,$actor,$action->id,$trace,
                    $p['reason']??null,$p['expected_revision']??null,$key
                ),
                default=>throw new \DomainException('Unsupported Finance Action type.')
            };
            return ExecutionResult::success([
                'finance_reference'=>$result['id'],
                'state'=>$result['state'],
                'envelope_id'=>$result['envelope_id']??$result['id'],
                'finance_receipt_type'=>$action->type,
                'provider_spend_confirmed'=>false
            ]);
        }catch(Throwable $e){
            error_log('finance.budget.action_failed '.$action->type.' '.$e::class);
            return ExecutionResult::failure('Finance budget operation blocked; inspect Finance receipt before any retry.');
        }
    }
}

