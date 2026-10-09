<?php
declare(strict_types=1);

namespace Domains\Growth\Automation\Action;

use Domains\Growth\Application\Contract\GrowthMarketDiscoveryBoundary;
use Kernel\Action\Action;
use Kernel\Action\ActionStatus;
use Kernel\Action\Contract\IdempotentExternalActionHandlerInterface;
use Kernel\Action\ExecutionResult;
use Kernel\Action\ExternalActionIdempotency;
use Throwable;

/**
 * Domain-owned binding for existing Growth Market Discovery. A batch is not proof
 * of 50 qualified prospects: the Goal outcome requires separate persisted evidence.
 */
final readonly class FederatedMarketDiscoveryHandler implements IdempotentExternalActionHandlerInterface
{
    public const TYPE = 'growth.market.discovery';

    public function __construct(private GrowthMarketDiscoveryBoundary $market) {}

    public function supports(string $actionType): bool { return $actionType === self::TYPE; }

    public function idempotencyKey(Action $action): string { return ExternalActionIdempotency::resolve($action); }

    public function execute(Action $action): ExecutionResult
    {
        $limit = $action->parameters['limit'] ?? null;
        if ($action->status !== ActionStatus::Running
            || $action->sourceType !== 'USER' || !ctype_digit($action->sourceId) || (int) $action->sourceId < 1
            || $action->targetType !== 'growth_market_universe'
            || !is_string($action->targetId) || trim($action->targetId) === ''
            || !is_int($limit) || $limit < 1 || $limit > 200) {
            return ExecutionResult::failure('Growth discovery requires a running approved user Action, a market universe and limit 1..200.');
        }
        try {
            $run = $this->market->runUniverse(
                $action->organizationId, (int) $action->sourceId,
                $action->correlationId !== '' ? $action->correlationId : $action->id,
                $action->targetId, $this->idempotencyKey($action), $limit,
            );
            $status = $run['status'] ?? null;
            if ($status !== 'completed') {
                return ExecutionResult::failure('Growth discovery requires manual review: ' .
                    (is_string($status) ? $status : 'invalid_status'));
            }
            if (!is_string($run['run_id'] ?? null) || $run['run_id'] === '') {
                return ExecutionResult::failure('Growth discovery returned no persisted run identity.');
            }
            return ExecutionResult::success([
                'run_id' => $run['run_id'],
                'universe_id' => $action->targetId,
                'status' => 'completed',
                'collected_count' => (int) ($run['collected_count'] ?? 0),
                'account_count' => (int) ($run['account_count'] ?? 0),
                'opportunity_count' => (int) ($run['opportunity_count'] ?? 0),
                'goal_outcome_verified' => false,
            ]);
        } catch (Throwable $error) {
            return ExecutionResult::failure('Growth discovery rejected: ' . $error->getMessage());
        }
    }
}
