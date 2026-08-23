<?php
declare(strict_types=1);

namespace Domains\Sales\Event;

use DateTimeImmutable;
use Kernel\Action\Action;
use Kernel\Action\ExecutionResult;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ActionExecuted
{
    public static function create(Action $action, ExecutionResult $result, string $workerId): DomainEvent
    {
        $type = match ($action->type) {
            'sales.send_message', 'sales.send_followup', 'sales.send_financing_followup' => 'sales.followup.sent',
            'sales.schedule_followup' => 'sales.followup.scheduled',
            'sales.update_deal' => 'sales.deal.updated',
            'sales.create_task', 'sales.create_followup_task', 'sales.create_qualification_task' => 'sales.task.created',
            default => $result->successful ? 'action.completed' : 'action.failed',
        };
        if (!$result->successful) $type = 'action.failed';

        return new DomainEvent(
            bin2hex(random_bytes(16)), $action->organizationId, $type,
            $action->targetType ?? 'action', $action->targetId ?? $action->id,
            [
                'action_id' => $action->id, 'action_type' => $action->type,
                'status' => $result->status(), 'output' => $result->data,
                'error' => $result->error, 'metrics' => $result->metrics,
            ],
            new EventMetadata($action->correlationId ?: $action->id, $action->id, 'WORKER', $workerId),
            new DateTimeImmutable(),
        );
    }
}
