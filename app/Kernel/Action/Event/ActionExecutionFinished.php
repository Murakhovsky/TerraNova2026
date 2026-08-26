<?php
declare(strict_types=1);

namespace Kernel\Action\Event;

use DateTimeImmutable;
use Kernel\Action\Action;
use Kernel\Action\ExecutionResult;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ActionExecutionFinished
{
    public const COMPLETED = 'cos.action.completed';
    public const FAILED = 'cos.action.failed';

    public static function create(Action $action, ExecutionResult $result, string $workerId): DomainEvent
    {
        return new DomainEvent(
            bin2hex(random_bytes(16)),
            $action->organizationId,
            $result->successful ? self::COMPLETED : self::FAILED,
            $action->targetType ?? 'action',
            $action->targetId ?? $action->id,
            [
                'action_id' => $action->id,
                'action_type' => $action->type,
                'status' => $result->status(),
                'output' => $result->data,
                'error' => $result->error,
                'metrics' => $result->metrics,
            ],
            new EventMetadata($action->correlationId ?: $action->id, $action->id, 'WORKER', $workerId),
            new DateTimeImmutable(),
        );
    }
}
