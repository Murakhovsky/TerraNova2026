<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthApplicationBoundary;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/** Domain-owned, idempotent transition from qualified to ready_for_handoff. */
final readonly class FederatedPrepareHandoffHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'growth.handoff.prepare';

    public function __construct(private GrowthApplicationBoundary $growth) {}
    public function supports(string $type): bool { return $type === self::TYPE; }
    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        $params = $action->parameters;
        foreach (['expected_value', 'recommended_play', 'recommended_action'] as $field) {
            if (!is_string($params[$field] ?? null) || trim($params[$field]) === '') {
                return ExecutionResult::failure('Handoff preparation requires an approved ' . $field . '.');
            }
        }
        if ($action->status !== ActionStatus::Running || $action->sourceType !== 'USER'
            || !ctype_digit($action->sourceId) || (int)$action->sourceId < 1
            || $action->targetType !== 'growth_candidate'
            || !is_string($action->targetId) || trim($action->targetId) === '') {
            return ExecutionResult::failure('Handoff preparation requires a running, approved user Action and Growth Candidate.');
        }
        try {
            // Existing Growth Domain service independently enforces scored, qualified,
            // tenant-owned Candidate and records durable mutation evidence.
            $prepared = $this->growth->prepareHandoff(
                $action->organizationId, (int)$action->sourceId,
                $action->correlationId !== '' ? $action->correlationId : $action->id,
                $action->targetId, $this->idempotencyKey($action), $params,
            );
            $candidate = $prepared['candidate'] ?? null;
            $handoff = $prepared['handoff'] ?? null;
            if (!is_array($candidate) || ($candidate['status'] ?? null) !== 'ready_for_handoff'
                || !is_array($handoff) || ($handoff['target_domain'] ?? null) !== 'sales') {
                return ExecutionResult::failure('Prepared candidate has no persisted Sales-targeted handoff package.');
            }
            return ExecutionResult::success([
                'candidate_id' => $action->targetId,
                'status' => 'ready_for_handoff',
                'target_domain' => 'sales',
                'goal_outcome_verified' => false,
            ]);
        } catch (Throwable $error) {
            return ExecutionResult::failure('Growth handoff preparation rejected: ' . $error->getMessage());
        }
    }
}
