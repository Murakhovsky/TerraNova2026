<?php
declare(strict_types=1);

namespace Kernel\Queue\Handler;

use Kernel\Action\Service\ActionService;
use Kernel\Action\ActionStatus;
use Kernel\Queue\Contract\JobHandlerInterface;
use Kernel\Queue\Job;
use RuntimeException;

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
        if ($actionId === '') throw new RuntimeException('ACTION_EXECUTION job requires action_id.');

        $workerId = $job->claimedBy !== '' ? $job->claimedBy : 'queue-worker';
        $action = $this->actions->execute($job->organizationId, $actionId, $workerId);
        if ($action === null) {
            $existing = $this->actions->find($job->organizationId, $actionId);
            if ($existing?->status->value === 'COMPLETED') return;
            if ($existing?->status === ActionStatus::Failed) {
                $this->actions->queue($job->organizationId, $actionId);
                $action = $this->actions->execute($job->organizationId, $actionId, $workerId);
            }
        }
        if ($action === null) {
            throw new RuntimeException('Action is not available for execution: ' . $actionId);
        }
        if ($action->status->value !== 'COMPLETED') {
            throw new RuntimeException('Action execution failed: ' . $actionId);
        }
    }
}
