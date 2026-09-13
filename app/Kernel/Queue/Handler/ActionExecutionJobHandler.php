<?php
declare(strict_types=1);

namespace Kernel\Queue\Handler;

use Kernel\Action\ActionExecutionOutcome;
use Kernel\Action\ActionStatus;
use Kernel\Action\Service\ActionService;
use Kernel\Execution\ExecutionFailureException;
use Kernel\Execution\ExecutionFailureKind;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;

final readonly class ActionExecutionJobHandler implements JobHandlerInterface
{
    public const TYPE = 'ACTION_EXECUTION';

    public function __construct(private ActionService $actions) {}

    public function supports(string $type): bool
    {
        return $type === self::TYPE;
    }

    public function handle(Job $job): void
    {
        $actionId = (string) ($job->payload['action_id'] ?? '');
        if ($actionId === '') {
            throw ExecutionFailureException::permanent('ACTION_EXECUTION job requires action_id.');
        }

        $workerId = $job->claimedBy !== '' ? $job->claimedBy : 'queue-worker';
        $outcome = $this->actions->executeOutcome($job->organizationId, $actionId, $workerId);
        if ($outcome === null) {
            $existing = $this->actions->find($job->organizationId, $actionId);
            if ($existing?->status === ActionStatus::Completed) return;
            if ($existing?->status === ActionStatus::Failed) {
                $this->actions->queue($job->organizationId, $actionId);
                $outcome = $this->actions->executeOutcome($job->organizationId, $actionId, $workerId);
            }
        }

        if ($outcome === null) {
            throw ExecutionFailureException::concurrencyConflict(
                'Action is not available for execution: ' . $actionId,
            );
        }

        $this->assertSuccessful($outcome);
    }

    private function assertSuccessful(ActionExecutionOutcome $outcome): void
    {
        if (!$outcome->result->successful) {
            throw new ExecutionFailureException(
                $outcome->result->failureKind ?? ExecutionFailureKind::Permanent,
                $outcome->result->error ?? ('Action execution failed: ' . $outcome->action()->id),
            );
        }

        if ($outcome->action()->status !== ActionStatus::Completed) {
            throw ExecutionFailureException::concurrencyConflict(
                'Action execution did not reach COMPLETED: ' . $outcome->action()->id,
            );
        }
    }
}
