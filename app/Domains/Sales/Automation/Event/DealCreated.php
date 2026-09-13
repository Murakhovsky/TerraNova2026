<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DealCreated
{
    public const TYPE = 'sales.deal.created';

    public static function create(
        string $id,
        string $organizationId,
        string $dealId,
        string $pipelineId,
        string $stageId,
        string $stageCode,
        EventMetadata $metadata,
        ?DateTimeImmutable $occurredAt = null,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'deal',
            $dealId,
            [
                'pipeline_id' => $pipelineId,
                'stage_id' => $stageId,
                'stage_code' => $stageCode,
            ],
            $metadata,
            $occurredAt ?? new DateTimeImmutable(),
        );
    }
}
