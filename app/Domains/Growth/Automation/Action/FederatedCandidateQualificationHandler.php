<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthDecisionBoundary;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/**
 * A deterministic, tenant-scoped qualification policy evaluation, not an LLM
 * declaration that the prospect is qualified. Monitor/disqualified are valid
 * recorded decisions, but they are NOT qualified-lead business outcomes.
 */
final readonly class FederatedCandidateQualificationHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'growth.candidate.qualify';

    public function __construct(private GrowthDecisionBoundary $decisions) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        $policyId = $action->parameters['policy_id'] ?? null;
        $revision = $action->parameters['policy_revision'] ?? null;
        if ($action->status !== ActionStatus::Running
            || $action->sourceType !== 'USER' || !ctype_digit($action->sourceId) || (int) $action->sourceId < 1
            || $action->targetType !== 'growth_candidate'
            || !is_string($action->targetId) || trim($action->targetId) === ''
            || !is_string($policyId) || trim($policyId) === ''
            || !is_int($revision) || $revision < 1) {
            return ExecutionResult::failure('Growth qualification requires a running user Action, Candidate and approved qualification policy revision.');
        }
        try {
            $result = $this->decisions->evaluateCandidate(
                $action->organizationId, (int) $action->sourceId,
                $action->correlationId !== '' ? $action->correlationId : $action->id,
                $action->targetId, $policyId, $revision, $this->idempotencyKey($action),
            );
            $outcome = $result['outcome'] ?? null;
            if (!is_string($result['evaluation_id'] ?? null) || $result['evaluation_id'] === ''
                || !in_array($outcome, ['qualified','monitor','disqualified'], true)) {
                return ExecutionResult::failure('Growth qualification returned no persisted policy decision.');
            }
            return ExecutionResult::success([
                'candidate_id' => $action->targetId,
                'evaluation_id' => $result['evaluation_id'],
                'policy_id' => $policyId,
                'policy_revision' => $revision,
                'outcome' => $outcome,
                'goal_outcome_verified' => false,
            ]);
        } catch (Throwable $error) {
            return ExecutionResult::failure('Growth qualification rejected: ' . $error->getMessage());
        }
    }
}
