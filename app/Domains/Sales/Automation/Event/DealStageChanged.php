<?php
declare(strict_types=1);

namespace Domains\Sales\Automation\Event;

use DateTimeImmutable;
use Kernel\Event\DomainEvent;
use Kernel\Event\EventMetadata;

final class DealStageChanged
{
    public const TYPE = 'sales.deal.stage_changed';

    public static function create(
        string $id,
        string $organizationId,
        string $dealId,
        string $pipelineId,
        string $previousStageId,
        string $previousStageCode,
        string $stageId,
        string $stageCode,
        EventMetadata $metadata,
    ): DomainEvent {
        return new DomainEvent(
            $id,
            $organizationId,
            self::TYPE,
            'deal',
            $dealId,
            [
                'pipeline_id' => $pipelineId,
                'previous_stage_id' => $previousStageId,
                'previous_stage_code' => $previousStageCode,
                'stage_id' => $stageId,
                'stage_code' => $stageCode,
            ],
            $metadata,
            new DateTimeImmutable(),
        );
    }
}
