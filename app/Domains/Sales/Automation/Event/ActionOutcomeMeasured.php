<?php
declare(strict_types=1);
namespace Domains\Sales\Automation\Event;

use Domains\Sales\Application\DTO\RecordActionOutcomeCommand;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class ActionOutcomeMeasured
{
    public const TYPE = 'sales.action_outcome.measured';

    public static function create(string $id, string $outcomeId, RecordActionOutcomeCommand $command): DomainEvent
    {
        return new DomainEvent($id, $command->organizationId, self::TYPE, 'action', $command->actionId, [
            'outcome_id'=>$outcomeId,'metric'=>$command->metric,'value'=>$command->value,
            'attribution_type'=>$command->attribution->value,'measured_at'=>$command->measuredAt->format(DATE_ATOM),
        ], new EventMetadata($command->correlationId, null, $command->actorType, $command->actorId), $command->measuredAt);
    }
}
