<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthHandoffBoundary;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/**
 * Delegate to existing Growth→Sales handoff: the Sales target creates exactly
 * one native Lead, while Growth owns the idempotent accepted receipt.
 */
final readonly class FederatedSalesHandoffHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'growth.handoff.target.sales';

    public function __construct(private GrowthHandoffBoundary $handoffs) {}
    public function supports(string $actionType): bool { return $actionType === self::TYPE; }
    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        if ($action->status !== ActionStatus::Running || $action->sourceType !== 'USER'
            || !ctype_digit($action->sourceId) || (int)$action->sourceId < 1
            || $action->targetType !== 'growth_candidate'
            || !is_string($action->targetId) || trim($action->targetId)==='') {
            return ExecutionResult::failure('Growth Sales handoff requires a running approved user Action and native Growth Candidate.');
        }
        try {
            $org=$action->organizationId;
            $context=$this->handoffs->handoffBrief($org,$action->targetId);
            $candidate=$context['candidate'] ?? null;
            if (!is_array($candidate)
                || ($candidate['target_domain'] ?? null) !== 'sales'
                || ($candidate['status'] ?? null) !== 'ready_for_handoff') {
                return ExecutionResult::failure('Only a Sales-targeted, ready-for-handoff Growth Candidate is eligible.');
            }
            $result=$this->handoffs->dispatch(
                $org,(int)$action->sourceId,
                $action->correlationId !== '' ? $action->correlationId : $action->id,
                $action->targetId,$this->idempotencyKey($action),
            );
            $attempt=$result['attempt'] ?? null;
            if (!is_array($attempt) || ($attempt['status'] ?? null) !== 'accepted'
                || ($attempt['target_domain'] ?? null) !== 'sales'
                || ($attempt['target_reference_type'] ?? null) !== 'sales_lead'
                || !is_string($attempt['target_reference_id'] ?? null)
                || !ctype_digit($attempt['target_reference_id'])
                || (int)$attempt['target_reference_id'] < 1) {
                return ExecutionResult::failure('Growth Sales handoff has no accepted native Sales Lead receipt; reconcile manually.');
            }
            return ExecutionResult::success([
                'candidate_id'=>$action->targetId,
                'handoff_attempt_id'=>(string)($attempt['attempt_id'] ?? ''),
                'sales_lead_id'=>$attempt['target_reference_id'],
                'status'=>'accepted',
                'goal_outcome_verified'=>false,
            ]);
        } catch (Throwable $failure) {
            return ExecutionResult::failure('Growth Sales handoff requires manual reconciliation: '.$failure->getMessage());
        }
    }
}
